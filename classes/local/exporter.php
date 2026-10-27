<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_miwlab\local;

use backup;
use base_setting;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * The backup of the CONTENT of a course for the MiwLab (1.6.0, issue 155): a regular course backup WITHOUT any data of a
 * person (no users, enrolments, role assignments, completion, grades history, logs, comments or badges awarded), made in the
 * background as the account that asked, kept in the course context (component local_miwlab, area contentbackup) for the
 * MiwLab to download in parts (local_miwlab_get_backup_chunk) with the secret returned once to the request that queued it, and
 * deleted once downloaded (or after a day). The file is never served by pluginfile.php: the service does not download files.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {
    /** File area of the content backups, in the course context. */
    const FILEAREA = 'contentbackup';
    /** Capabilities, in the course: the plugin's own and the core one to back up a course. */
    const CAPABILITIES = ['local/miwlab:backupcourse', 'moodle/backup:backupcourse'];
    /** Status of a content backup. */
    const STATUS_QUEUED = 0;
    /** Running. */
    const STATUS_RUNNING = 1;
    /** Finished: the file is in the area. */
    const STATUS_FINISHED = 2;
    /** Failed: the reason is recorded. */
    const STATUS_FAILED = 3;
    /** Hours a finished backup is kept if the MiwLab does not delete it. */
    const KEEP_HOURS = 24;
    /** The largest part returned by one call (bytes, before base64). */
    const MAX_CHUNK = 4 * 1024 * 1024;
    /**
     * The backup settings that carry data of a person, all off (the ones the account could not change keep the value of the
     * site, which the run checks after: users and anything personal must be off, or the backup fails).
     */
    const NO_PEOPLE = ['users' => 0, 'anonymize' => 0, 'role_assignments' => 0, 'comments' => 0, 'userscompletion' => 0,
        'logs' => 0, 'grade_histories' => 0, 'badges' => 0, 'competencies' => 1, 'files' => 1, 'activities' => 1, 'blocks' => 1,
        'filters' => 1, 'calendarevents' => 1, 'questionbank' => 1, 'groups' => 0, 'contentbankcontent' => 1, 'legacyfiles' => 1];

    /**
     * The course, never the site.
     *
     * @param int $courseid
     * @return stdClass
     */
    public static function course(int $courseid): stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        return $course;
    }

    /**
     * Checks the capabilities in the course, naming the missing ones.
     *
     * @param \context_course $context
     */
    public static function require_capabilities(\context_course $context): void {
        $missing = array_values(array_filter(self::CAPABILITIES, fn($c) => !has_capability($c, $context)));
        if ($missing) {
            throw new moodle_exception('error:missingcontentcapabilities', 'local_miwlab', '', implode(', ', $missing));
        }
    }

    /**
     * Queues the backup of a course.
     *
     * @param int $courseid
     * @return stdClass The record.
     */
    public static function queue(int $courseid): stdClass {
        global $DB, $USER;
        // The secret ties status, parts and deletion to this request: only its SHA-256 is kept, and it is returned once.
        $secret = bin2hex(random_bytes(20));
        $record = (object) ['courseid' => $courseid, 'status' => self::STATUS_QUEUED, 'filename' => '', 'filesize' => 0,
            'contenthash' => '', 'secrethash' => hash('sha256', $secret), 'errorcode' => null, 'errormessage' => null,
            'timecreated' => time(), 'timefinished' => 0];
        $record->id = $DB->insert_record('local_miwlab_backups', $record);
        $record->secret = $secret;
        $task = new \local_miwlab\task\backup_course();
        $task->set_custom_data(['id' => (int) $record->id]);
        $task->set_userid((int) $USER->id);
        \core\task\manager::queue_adhoc_task($task);
        return $record;
    }

    /**
     * Runs a queued backup (the background task, as the account that asked).
     *
     * @param int $id
     */
    public static function run(int $id): void {
        global $DB, $USER;
        $record = $DB->get_record('local_miwlab_backups', ['id' => $id]);
        if (!$record || (int) $record->status !== self::STATUS_QUEUED) {
            return;
        }
        $DB->update_record('local_miwlab_backups', (object) ['id' => $id, 'status' => self::STATUS_RUNNING]);
        $result = null;
        try {
            $course = self::course((int) $record->courseid);
            $context = \context_course::instance($course->id);
            self::require_capabilities($context);
            $bc = new \backup_controller(
                backup::TYPE_1COURSE,
                $course->id,
                backup::FORMAT_MOODLE,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                (int) $USER->id
            );
            try {
                $plan = $bc->get_plan();
                foreach (self::NO_PEOPLE as $name => $value) {
                    if ($plan->setting_exists($name) && $plan->get_setting($name)->get_status() == base_setting::NOT_LOCKED) {
                        $plan->get_setting($name)->set_value($value);
                    }
                }
                // 1.9.0: the role has moodle/backup:userinfo, so the copy no longer lacks people just because of the permission:
                // the people settings are set off explicitly (lifting the lock by permission) and checked after, the root
                // ones and the
                // user data of each activity and block.
                backup_scope::exclude_people($plan);
                $bc->execute_plan();
                $result = $bc->get_results()['backup_destination'] ?? null;
            } finally {
                $bc->destroy();
            }
            if (!$result instanceof \stored_file || $result->get_filesize() <= 0) {
                throw new moodle_exception('error:backupempty', 'local_miwlab');
            }
            $filename = 'miwlab-content-course-' . $course->id . '-' . date('Ymd-His') . '.mbz';
            $file = get_file_storage()->create_file_from_storedfile([
                'contextid' => $context->id,
                'component' => 'local_miwlab',
                'filearea' => self::FILEAREA,
                'itemid' => $id,
                'filepath' => '/',
                'filename' => $filename,
                'userid' => null,
            ], $result);
            $DB->update_record('local_miwlab_backups', (object) ['id' => $id, 'status' => self::STATUS_FINISHED,
                'filename' => $filename, 'filesize' => $file->get_filesize(), 'contenthash' => $file->get_contenthash(),
                'timefinished' => time()]);
        } catch (\Throwable $e) {
            $errorcode = $e instanceof moodle_exception ? 'error:' . preg_replace('/^error:/', '', $e->errorcode) :
                'error:contentbackupfailed';
            $DB->update_record('local_miwlab_backups', (object) ['id' => $id, 'status' => self::STATUS_FAILED,
                'errorcode' => $errorcode,
                'errormessage' => restorer::reason($e), 'timefinished' => time()]);
        } finally {
            // The copy the controller left in the backup area of the course (or of the account) is not kept, on any path.
            if ($result instanceof \stored_file) {
                $result->delete();
            }
        }
    }

    /**
     * The status as the web service returns it.
     *
     * @param stdClass $record
     * @return array
     */
    public static function export(stdClass $record): array {
        $status = [self::STATUS_QUEUED => 'queued', self::STATUS_RUNNING => 'running', self::STATUS_FINISHED => 'finished',
            self::STATUS_FAILED => 'failed'][(int) $record->status] ?? 'failed';
        return [
            'backupid' => (int) $record->id,
            'courseid' => (int) $record->courseid,
            'status' => $status,
            'filename' => (string) $record->filename,
            'filesize' => (int) $record->filesize,
            'contenthash' => (string) $record->contenthash,
            'errorcode' => (string) ($record->errorcode ?? ''),
            'errormessage' => (string) ($record->errormessage ?? ''),
            'timecreated' => (int) $record->timecreated,
            'timefinished' => (int) $record->timefinished,
        ] + (isset($record->secret) ? ['secret' => (string) $record->secret] : []);
    }

    /**
     * The record of a backup, for the request that queued it: the secret has to match (a wrong one is answered as not found).
     *
     * @param int $backupid
     * @param string $secret
     * @return stdClass
     */
    public static function record(int $backupid, string $secret): stdClass {
        global $DB;
        $record = $DB->get_record('local_miwlab_backups', ['id' => $backupid]);
        if (!$record || $record->secrethash === '' || !hash_equals((string) $record->secrethash, hash('sha256', $secret))) {
            throw new moodle_exception('error:backupnotfound', 'local_miwlab');
        }
        return $record;
    }

    /**
     * A part of a finished backup file: from offset, at most length bytes.
     *
     * @param stdClass $record
     * @param int $offset
     * @param int $length
     * @return array ['offset', 'length', 'data' (base64), 'eof']
     */
    public static function chunk(stdClass $record, int $offset, int $length): array {
        if ((int) $record->status !== self::STATUS_FINISHED) {
            throw new moodle_exception('error:backupnotfound', 'local_miwlab');
        }
        $context = \context_course::instance((int) $record->courseid);
        $file = get_file_storage()->get_file(
            $context->id,
            'local_miwlab',
            self::FILEAREA,
            (int) $record->id,
            '/',
            $record->filename
        );
        if (!$file || $file->is_directory()) {
            throw new moodle_exception('error:backupnotfound', 'local_miwlab');
        }
        $size = (int) $file->get_filesize();
        if ($offset < 0 || $offset > $size) {
            throw new \invalid_parameter_exception('offset');
        }
        $length = max(1, min($length, self::MAX_CHUNK));
        $handle = $file->get_content_file_handle();
        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw new moodle_exception('error:contentbackupfailed', 'local_miwlab');
            }
            $data = '';
            while (strlen($data) < $length && !feof($handle)) {
                $part = fread($handle, $length - strlen($data));
                if ($part === false || $part === '') {
                    break;
                }
                $data .= $part;
            }
        } finally {
            fclose($handle);
        }
        return ['offset' => $offset, 'length' => strlen($data), 'data' => base64_encode($data),
            'eof' => $offset + strlen($data) >= $size];
    }

    /**
     * Deletes a content backup (the record and the file).
     *
     * @param stdClass $record
     */
    public static function delete(stdClass $record): void {
        global $DB;
        $context = \context_course::instance((int) $record->courseid, IGNORE_MISSING);
        if ($context) {
            get_file_storage()->delete_area_files($context->id, 'local_miwlab', self::FILEAREA, (int) $record->id);
        }
        $DB->delete_records('local_miwlab_backups', ['id' => $record->id]);
    }

    /**
     * Deletes the content backups (finished, failed or stalled) kept longer than KEEP_HOURS: the MiwLab deletes each one once
     * downloaded, and a backup whose background task stopped is not waited for longer than that.
     */
    public static function cleanup(): void {
        global $DB;
        $limit = time() - self::KEEP_HOURS * HOURSECS;
        foreach ($DB->get_records_select('local_miwlab_backups', 'timecreated < :limit', ['limit' => $limit]) as $record) {
            self::delete($record);
        }
    }
}
