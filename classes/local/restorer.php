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
use moodle_exception;
use restore_controller;
use restore_dbops;
use stdClass;

/**
 * Restores a MiwLab package (.mbz) as a new course in a category, in the background. From 1.5.0 the same queue also replaces
 * the content of an existing course (replacer, mode "replace").
 *
 * The package is uploaded beforehand to the draft area of the account (webservice/upload.php or core_files_upload).
 * The plugin keeps only the progress of each restore (table local_miwlab_restores): no data of a person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restorer {
    /** Waiting for the background task. */
    const STATUS_QUEUED = 0;
    /** The background task is restoring. */
    const STATUS_RUNNING = 1;
    /** The course exists and is complete. */
    const STATUS_FINISHED = 2;
    /** The restore failed; the half-made course, if any, was deleted. */
    const STATUS_FAILED = 3;

    /** @var string[] Status names, as the web service returns them. */
    const STATUS_NAMES = [
        self::STATUS_QUEUED => 'queued',
        self::STATUS_RUNNING => 'running',
        self::STATUS_FINISHED => 'finished',
        self::STATUS_FAILED => 'failed',
    ];

    /** Mode of a restore record that creates a new course. */
    const MODE_NEW = 'new';

    /** @var string[] Capabilities needed in the category, all of them. */
    const CAPABILITIES = ['local/miwlab:restorecourse', 'moodle/restore:restorecourse', 'moodle/course:create'];

    /**
     * Capability needed in the restored course to activate the badges the package brought (1.6.0). Without it the restore
     * still finishes, with the badges inactive, as a restore by hand leaves them.
     */
    const BADGES_CAPABILITY = 'moodle/badges:configurecriteria';

    /** Pattern of the marker, at most 88 characters: the course ID number has 100, and a repeated marker gets ":<course id>". */
    const MARKER_PATTERN = '/^miwlab:[A-Za-z0-9_-]{1,60}(:[A-Za-z0-9_-]{1,20})?$/';

    /**
     * The capabilities the current user lacks in a category, to restore there.
     *
     * @param \context_coursecat $context
     * @return string[]
     */
    public static function missing_capabilities(\context_coursecat $context): array {
        $missing = [];
        foreach (self::CAPABILITIES as $capability) {
            if (!has_capability($capability, $context)) {
                $missing[] = $capability;
            }
        }
        return $missing;
    }

    /**
     * Refuses, naming every missing capability, when the current user cannot restore in the category.
     *
     * @param \context_coursecat $context
     * @throws moodle_exception
     */
    public static function require_capabilities(\context_coursecat $context): void {
        $missing = self::missing_capabilities($context);
        if ($missing) {
            $category = \core_course_category::get($context->instanceid, IGNORE_MISSING, true);
            throw new moodle_exception('error:missingcapabilities', 'local_miwlab', '', (object) [
                'category' => $category ? $category->get_formatted_name() : $context->instanceid,
                'capabilities' => implode(', ', $missing),
            ]);
        }
    }

    /**
     * Whether a marker is acceptable.
     *
     * @param string $marker
     * @return bool
     */
    public static function valid_marker(string $marker): bool {
        return (bool) preg_match(self::MARKER_PATTERN, $marker);
    }

    /**
     * The package in the draft area of the current user.
     *
     * @param int $draftitemid
     * @param string $filename Empty: the only file of the area.
     * @return \stored_file
     * @throws moodle_exception
     */
    public static function draft_file(int $draftitemid, string $filename = ''): \stored_file {
        global $USER;
        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        if ($filename !== '') {
            $files = array_filter($files, function (\stored_file $f) use ($filename): bool {
                return $f->get_filename() === $filename;
            });
        }
        if (!$files) {
            throw new moodle_exception('error:draftfilenotfound', 'local_miwlab');
        }
        if (count($files) > 1) {
            throw new moodle_exception('error:draftfiletoomany', 'local_miwlab');
        }
        $file = reset($files);
        if ($file->get_filesize() <= 0) {
            throw new moodle_exception('error:draftfileempty', 'local_miwlab');
        }
        return $file;
    }

    /**
     * Queues the restore of the package in the category; the course is created by the background task.
     *
     * The caller has validated the context and the capabilities.
     *
     * @param int $categoryid
     * @param \stored_file $file
     * @param string $marker Empty: the marker is read from the package, if it has one.
     * @return stdClass The record of the restore.
     */
    public static function queue(int $categoryid, \stored_file $file, string $marker): stdClass {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $now = time();
        $record = (object) [
            'restoreid' => restore_controller::get_tempdir_name($categoryid, $USER->id),
            'categoryid' => $categoryid,
            'courseid' => 0,
            'marker' => $marker === '' ? null : $marker,
            'status' => self::STATUS_QUEUED,
            'progress' => 0,
            'timecreated' => $now,
            'timestarted' => 0,
            'timefinished' => 0,
        ];
        $record->id = $DB->insert_record('local_miwlab_restores', $record);

        $task = new \local_miwlab\task\restore_course();
        $task->set_userid($USER->id);
        $task->set_custom_data((object) ['id' => (int) $record->id, 'fileid' => (int) $file->get_id()]);
        \core\task\manager::queue_adhoc_task($task);
        return $record;
    }

    /**
     * Runs a queued restore, as the user who queued it (the background task sets the user).
     *
     * Never throws: a failure is recorded in the restore, with the reason, and the half-made course is deleted.
     *
     * @param int $id Record of the restore.
     * @param int $fileid The package in the draft area.
     */
    public static function run(int $id, int $fileid): void {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $record = $DB->get_record('local_miwlab_restores', ['id' => $id]);
        if (!$record || (int) $record->status !== self::STATUS_QUEUED) {
            return;
        }
        $record->status = self::STATUS_RUNNING;
        $record->timestarted = time();
        $DB->update_record('local_miwlab_restores', $record);

        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        // Only the package in the draft area of the user running the restore is read, and then deleted.
        $usercontextid = (int) \context_user::instance($USER->id)->id;
        $indraft = $file && $file->get_component() === 'user' && $file->get_filearea() === 'draft';
        if (!$indraft || (int) $file->get_contextid() !== $usercontextid) {
            $file = false;
        }
        $tempdir = make_backup_temp_directory($record->restoreid);
        if (($record->mode ?? self::MODE_NEW) === replacer::MODE) {
            try {
                replacer::run($record, $file, $tempdir);
            } finally {
                fulldelete($tempdir);
                if ($file) {
                    $file->delete();
                }
            }
            return;
        }
        $controller = null;
        $keptopics = '';
        try {
            $context = \context_coursecat::instance($record->categoryid, IGNORE_MISSING);
            if (!$context) {
                throw new moodle_exception('error:categorynotfound', 'local_miwlab');
            }
            // The capabilities are checked again: they may have changed while the restore was waiting.
            self::require_capabilities($context);
            if (!$file || $file->is_directory()) {
                throw new moodle_exception('error:draftfilenotfound', 'local_miwlab');
            }
            $marker = self::extract($file, $tempdir, (string) $record->marker);
            // 1.7.0 (issue 178): Moodle deletes the extracted package when the restore ends, so the topics are kept aside first.
            $keepfailure = '';
            try {
                $keptopics = forum_topics::keep($tempdir);
            } catch (moodle_exception $e) {
                $keepfailure = $e->getMessage();
            }

            [$fullname, $shortname] = restore_dbops::calculate_course_names(
                0,
                get_string('restoringcourse', 'backup'),
                get_string('restoringcourseshortname', 'backup')
            );
            $courseid = restore_dbops::create_new_course($fullname, $shortname, $record->categoryid);
            $DB->set_field('local_miwlab_restores', 'courseid', $courseid, ['id' => $record->id]);
            $record->courseid = $courseid;

            $controller = new restore_controller(
                $record->restoreid,
                $courseid,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                (int) $USER->id,
                backup::TARGET_NEW_COURSE
            );
            $controller->set_progress(new \core\progress\db_updater($record->id, 'local_miwlab_restores', 'progress'));
            if (!$controller->execute_precheck()) {
                $results = $controller->get_precheck_results();
                if (!empty($results['errors'])) {
                    throw new moodle_exception('error:precheck', 'local_miwlab', '', implode(' ', $results['errors']));
                }
            }
            $controller->execute_plan();
            $controller->destroy();
            $controller = null;
            self::remove_restorer_access($courseid);
            // The badges of the package come inactive from the restore: the course is new, so every badge in it came
            // from the package. Activating them needs moodle/badges:configurecriteria in the course (without it, they stay
            // inactive, as a restore by hand leaves them).
            if (has_capability(self::BADGES_CAPABILITY, \context_course::instance($courseid))) {
                badges::activate_restored($courseid, []);
            }

            // 1.7.0 (issue 178): the opening topics of the forums that the package carries, as the account of the integration.
            $topics = $keepfailure !== ''
                ? ['created' => 0, 'skipped' => 1, 'existing' => 0, 'note' => $keepfailure]
                : forum_topics::create_from_package($courseid, $keptopics);

            if ($marker !== '') {
                // The ID number is unique: a second restore of the same course gets the marker followed by its own id.
                $taken = $DB->record_exists_select(
                    'course',
                    'idnumber = :marker AND id <> :id',
                    ['marker' => $marker, 'id' => $courseid]
                );
                if ($taken) {
                    $marker .= ':' . $courseid;
                }
                $DB->set_field('course', 'idnumber', $marker, ['id' => $courseid]);
                \cache_helper::purge_by_event('changesincourse');
                rebuild_course_cache($courseid, true);
            }
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'status' => self::STATUS_FINISHED,
                'progress' => 1,
                'timefinished' => time(),
                'topicscreated' => $topics['created'],
                'topicsskipped' => $topics['skipped'],
                'topicsnote' => $topics['note'] === '' ? null : $topics['note'],
            ]);
        } catch (\Throwable $e) {
            if ($controller) {
                $controller->destroy();
            }
            if (!empty($record->courseid) && $DB->record_exists('course', ['id' => $record->courseid])) {
                delete_course($record->courseid, false);
            }
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'status' => self::STATUS_FAILED,
                'courseid' => 0,
                'errorcode' => $e instanceof moodle_exception ? substr($e->errorcode, 0, 100) : 'unexpected',
                'errormessage' => self::reason($e),
                'timefinished' => time(),
            ]);
        } finally {
            fulldelete($tempdir);
            if ($keptopics !== '') {
                fulldelete($keptopics);
            }
            if ($file) {
                $file->delete();
            }
        }
    }

    /**
     * Takes back the access that Moodle gives to whoever restores a new course ("Restorers' role in courses").
     *
     * The integration account does not need to be a teacher of the courses it restores: it reads through the
     * capabilities of the plugin. The course is new, so every role and enrolment of the account in it came from the restore.
     *
     * @param int $courseid
     */
    protected static function remove_restorer_access(int $courseid): void {
        global $DB, $USER;
        $context = \context_course::instance($courseid);
        $instances = $DB->get_records_sql(
            "SELECT e.*
               FROM {enrol} e
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $courseid, 'userid' => $USER->id]
        );
        foreach ($instances as $instance) {
            $plugin = enrol_get_plugin($instance->enrol);
            if ($plugin) {
                $plugin->unenrol_user($instance, $USER->id);
            }
        }
        role_unassign_all(['userid' => $USER->id, 'contextid' => $context->id]);
    }

    /**
     * Extracts the package into the backup temp directory of the restore, after checking it looks like a backup, and
     * returns the marker to write: the one asked or, when none, the one the package carries.
     *
     * @param \stored_file $file
     * @param string $tempdir
     * @param string $marker
     * @return string
     * @throws moodle_exception
     */
    public static function extract(\stored_file $file, string $tempdir, string $marker): string {
        if (!self::looks_like_backup($file)) {
            throw new moodle_exception('error:notabackup', 'local_miwlab');
        }
        $packer = get_file_packer('application/vnd.moodle.backup');
        if (!$file->extract_to_pathname($packer, $tempdir)) {
            throw new moodle_exception('error:notabackup', 'local_miwlab');
        }
        if (!is_readable($tempdir . '/moodle_backup.xml')) {
            throw new moodle_exception('error:notabackup', 'local_miwlab');
        }
        return $marker !== '' ? $marker : self::marker_in_package($tempdir);
    }

    /**
     * What the package says of its course (course/course.xml) that the replacement uses: whether completion is tracked.
     *
     * @param string $dir The extracted package.
     * @return array ['enablecompletion' => bool]
     */
    public static function course_in_package(string $dir): array {
        $xml = self::course_xml($dir);
        return ['enablecompletion' => $xml && isset($xml->enablecompletion) && (int) $xml->enablecompletion === 1];
    }

    /**
     * The course/course.xml of an extracted package, read without the network, or null.
     *
     * @param string $dir
     * @return \SimpleXMLElement|null
     */
    protected static function course_xml(string $dir): ?\SimpleXMLElement {
        $path = $dir . '/course/course.xml';
        if (!is_readable($path) || filesize($path) > 5 * 1024 * 1024) {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        return $xml ?: null;
    }

    /**
     * Whether the file starts as a Moodle backup does: gzip (.mbz since Moodle 2.6) or zip.
     *
     * Checked before extracting, so that any other file is refused with a clear reason.
     *
     * @param \stored_file $file
     * @return bool
     */
    protected static function looks_like_backup(\stored_file $file): bool {
        $handle = $file->get_content_file_handle();
        if (!$handle) {
            return false;
        }
        $start = (string) fread($handle, 4);
        fclose($handle);
        return strpos($start, "\x1f\x8b") === 0 || $start === "PK\x03\x04";
    }

    /**
     * A readable reason for a failure: the message, and the debug detail of the restore when it explains the error.
     *
     * @param \Throwable $e
     * @return string
     */
    public static function reason(\Throwable $e): string {
        $reason = trim($e->getMessage());
        if ($e instanceof moodle_exception && !empty($e->debuginfo) && strpos($e->errorcode, 'error:') !== 0) {
            $reason .= ' (' . trim(strip_tags((string) $e->debuginfo)) . ')';
        }
        return \core_text::substr($reason, 0, 2000);
    }

    /**
     * The MiwLab marker the package carries in the course ID number (course/course.xml), if any.
     *
     * Moodle drops the ID number of a course restored from another site; the plugin writes it back.
     *
     * @param string $dir The extracted package.
     * @return string Empty when there is none.
     */
    public static function marker_in_package(string $dir): string {
        $xml = self::course_xml($dir);
        if (!$xml || !isset($xml->idnumber)) {
            return '';
        }
        $idnumber = trim((string) $xml->idnumber);
        return self::valid_marker($idnumber) ? $idnumber : '';
    }

    /**
     * The status of a restore, as the web service returns it.
     *
     * @param stdClass $record
     * @return array
     */
    public static function export(stdClass $record): array {
        global $DB;
        $status = (int) $record->status;
        $progress = 0.0;
        if ($status === self::STATUS_FINISHED) {
            $progress = 1.0;
        } else if ($status === self::STATUS_RUNNING) {
            $progress = (float) $record->progress;
        }
        $result = [
            'restoreid' => $record->restoreid,
            'status' => self::STATUS_NAMES[$status] ?? 'failed',
            'progress' => $progress,
            'categoryid' => (int) $record->categoryid,
            'courseid' => 0,
            'courseurl' => '',
            'coursefullname' => '',
            'courseshortname' => '',
            'marker' => '',
            'errorcode' => '',
            'errormessage' => '',
            'timecreated' => (int) $record->timecreated,
            'timestarted' => (int) $record->timestarted,
            'timefinished' => (int) $record->timefinished,
            'mode' => ($record->mode ?? self::MODE_NEW) === replacer::MODE ? replacer::MODE : self::MODE_NEW,
            'backupfilename' => (string) ($record->backupfilename ?? ''),
            'rolledback' => !empty($record->rolledback),
            'coursesignature' => '',
            'contentmodified' => 0,
            'topicscreated' => (int) ($record->topicscreated ?? 0),
            'topicsskipped' => (int) ($record->topicsskipped ?? 0),
            'topicsnote' => (string) ($record->topicsnote ?? ''),
        ];
        if ($status === self::STATUS_FINISHED) {
            $course = $DB->get_record('course', ['id' => $record->courseid]);
            if (!$course) {
                $result['errorcode'] = 'error:coursedeleted';
                $result['errormessage'] = get_string('error:coursedeleted', 'local_miwlab');
                return $result;
            }
            $result['courseid'] = (int) $course->id;
            $result['courseurl'] = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            $result['coursefullname'] = $course->fullname;
            $result['courseshortname'] = $course->shortname;
            $result['marker'] = (string) $course->idnumber;
            // The state of the content right after the MiwLab sent it (1.5.0): what it compares with before replacing it later.
            $state = replacer::course_state((int) $course->id);
            $result['coursesignature'] = $state['signature'];
            $result['contentmodified'] = $state['contentmodified'];
        } else if ($status === self::STATUS_FAILED) {
            $result['errorcode'] = (string) $record->errorcode;
            $result['errormessage'] = (string) $record->errormessage;
        }
        return $result;
    }
}
