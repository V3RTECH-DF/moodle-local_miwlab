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

use moodle_exception;
use stdClass;

/**
 * The safe update of a course (1.9.0, issue 204): the preview and the request to apply it. The course stays the same course, with
 * its people; what the MiwLab changed is sorted against the course AS IT IS and the progress of each activity (update_classifier),
 * shown, and then applied exactly (update_runner) in the background.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class updater {
    /** Mode of a restore record that applies a safe update. */
    const MODE = 'update';

    /** Seconds a preview can be applied. */
    const LIFETIME = 1800;

    /** Preview not applied yet. */
    const OPEN = 0;
    /** Preview already queued to be applied: it cannot be applied again. */
    const USED = 1;

    /** File area (course context) where the package of the new activities waits for the application. */
    const PACKAGE_AREA = 'updatepackage';

    /**
     * Capabilities needed in the course, all of them: the plugin's own, the backup made before and the restore that only adds.
     */
    const CAPABILITIES = [
        'local/miwlab:updatecourse',
        'moodle/backup:backupcourse',
        'moodle/restore:restorecourse',
        'moodle/restore:configure',
        'moodle/restore:rolldates',
    ];

    /**
     * Refuses, naming every missing capability, when the current user cannot update the course.
     *
     * @param \context_course $context
     * @param stdClass $course
     * @param string[] $extra Capabilities besides CAPABILITIES.
     * @throws moodle_exception
     */
    public static function require_capabilities(\context_course $context, stdClass $course, array $extra = []): void {
        replacer::require_capabilities($context, $course, array_merge(self::CAPABILITIES, $extra));
    }

    /**
     * Makes the preview: sorts the operations and stores the preview (and the package of the new activities, if any).
     *
     * @param stdClass $course
     * @param string $operations The JSON list.
     * @param string $expectedsignature The state of the course the MiwLab knows; refused if it changed ('' none).
     * @param \stored_file|null $package The package with only the new activities and sections.
     * @return array The preview as the web service returns it.
     */
    public static function preview(stdClass $course, string $operations, string $expectedsignature, ?\stored_file $package): array {
        global $DB, $USER;
        replacer::require_unchanged($course->id, $expectedsignature, 0);
        $ops = update_operations::parse((int) $course->id, $operations);
        $needspackage = $ops['added'] || $ops['newsections'];
        if ($needspackage && !$package) {
            throw new moodle_exception('error:draftfilenotfound', 'local_miwlab');
        }
        if (!$needspackage && $package) {
            throw new moodle_exception('error:invalidoperations', 'local_miwlab', '', 'a package without new activities');
        }
        $state = replacer::course_state((int) $course->id);
        $people = replacer::people((int) $course->id);
        $progress = replacer::progress_by_activity((int) $course->id);
        $items = update_classifier::classify($course, $ops, $progress, $people['enrolled']);
        $unknown = replacer::unknown_modules((int) $course->id);
        $now = time();
        $record = (object) [
            'previewid' => bin2hex(random_bytes(16)),
            'courseid' => (int) $course->id,
            'accountid' => (int) $USER->id,
            'signature' => $state['signature'],
            'operations' => json_encode($ops),
            'items' => json_encode($items),
            'status' => self::OPEN,
            'timecreated' => $now,
            'timeexpires' => $now + self::LIFETIME,
        ];
        $record->id = $DB->insert_record('local_miwlab_updates', $record);
        if ($needspackage) {
            try {
                self::inspect_package($package, $course, $ops);
                get_file_storage()->create_file_from_storedfile([
                    'contextid' => \context_course::instance($course->id)->id,
                    'component' => 'local_miwlab',
                    'filearea' => self::PACKAGE_AREA,
                    'itemid' => (int) $record->id,
                    'filepath' => '/',
                    'filename' => 'package.mbz',
                ], $package);
            } catch (\Throwable $e) {
                $DB->delete_records('local_miwlab_updates', ['id' => $record->id]);
                throw $e;
            }
        }
        return self::export($record, $items, $people, $unknown);
    }

    /**
     * The preview as the web service returns it.
     *
     * @param stdClass $record
     * @param array|null $items
     * @param array|null $people
     * @param array|null $unknown
     * @return array
     */
    public static function export(stdClass $record, ?array $items = null, ?array $people = null, ?array $unknown = null): array {
        $items = $items ?? json_decode($record->items, true);
        $people = $people ?? replacer::people((int) $record->courseid);
        $counts = ['update' => 0, 'add' => 0, 'skip' => 0, 'choice' => 0];
        foreach ($items as $i) {
            $counts[$i['group']] = ($counts[$i['group']] ?? 0) + 1;
        }
        return [
            'previewid' => $record->previewid,
            'courseid' => (int) $record->courseid,
            'signature' => $record->signature,
            'expires' => (int) $record->timeexpires,
            'enrolled' => $people['enrolled'],
            'withprogress' => $people['withprogress'],
            'unknownmodules' => $unknown ?? replacer::unknown_modules((int) $record->courseid),
            'counts' => $counts,
            'items' => array_map([self::class, 'export_item'], $items),
        ];
    }

    /**
     * One item, with every field present (the web service structure has no optional ones).
     *
     * @param array $i
     * @return array
     */
    protected static function export_item(array $i): array {
        return [
            'id' => (string) $i['id'],
            'group' => (string) $i['group'],
            'kind' => (string) $i['kind'],
            'ref' => (string) ($i['ref'] ?? ''),
            'module' => (string) $i['module'],
            'name' => (string) $i['name'],
            'section' => (string) $i['section'],
            'field' => (string) $i['field'],
            'action' => (string) $i['action'],
            'reason' => (string) $i['reason'],
            'people' => (int) $i['people'],
            'kept' => (int) ($i['kept'] ?? 0),
            'warnings' => array_values($i['warnings']),
            'suggestdelete' => (bool) $i['suggestdelete'],
            'choice' => (string) ($i['choice'] ?? ''),
            'canchoose' => (bool) ($i['canchoose'] ?? false),
            'needsdiscard' => (bool) ($i['needsdiscard'] ?? false),
        ];
    }

    /**
     * Whether an update is queued or running for the course.
     *
     * @param int $courseid
     * @return bool
     */
    public static function in_progress(int $courseid): bool {
        global $DB;
        return $DB->record_exists_select(
            'local_miwlab_restores',
            'mode = :mode AND courseid = :courseid AND status IN (:queued, :running)',
            ['mode' => self::MODE, 'courseid' => $courseid, 'queued' => restorer::STATUS_QUEUED,
                'running' => restorer::STATUS_RUNNING]
        );
    }

    /**
     * The open preview of this account for the course.
     *
     * @param string $previewid
     * @return stdClass
     * @throws moodle_exception error:previewnotfound, error:previewexpired or error:previewused
     */
    public static function open_preview(string $previewid): stdClass {
        global $DB, $USER;
        $record = $DB->get_record('local_miwlab_updates', ['previewid' => $previewid]);
        // Another account, or a preview of a course it cannot reach, is "not found": nothing is said about it.
        if (!$record || (int) $record->accountid !== (int) $USER->id) {
            throw new moodle_exception('error:previewnotfound', 'local_miwlab');
        }
        if ((int) $record->status !== self::OPEN) {
            throw new moodle_exception('error:previewused', 'local_miwlab');
        }
        if ((int) $record->timeexpires < time()) {
            throw new moodle_exception('error:previewexpired', 'local_miwlab');
        }
        return $record;
    }

    /**
     * Queues the application of a preview; the background task applies exactly what the preview showed.
     *
     * @param stdClass $record The open preview (open_preview()).
     * @param bool $forcediscard The conscious confirmation: the person who sends knows who loses the completion of the course.
     * @param int $keepdays Days the full backup is kept (when one is needed).
     * @return stdClass The record of the restore.
     */
    public static function queue(stdClass $record, bool $forcediscard, int $keepdays): stdClass {
        global $CFG, $DB, $USER;
        fullcopies::require_valid_days($keepdays);
        $course = replacer::course((int) $record->courseid);
        $context = \context_course::instance($course->id);
        self::require_capabilities($context, $course);
        if (replacer::in_progress($course->id) || self::in_progress($course->id)) {
            throw new moodle_exception('error:replaceinprogress', 'local_miwlab');
        }
        replacer::require_unchanged($course->id, $record->signature, 0);
        $needsdiscard = false;
        foreach (json_decode($record->items, true) as $i) {
            if (!empty($i['needsdiscard']) && $i['choice'] === 'replace') {
                $needsdiscard = true;
            }
        }
        if ($needsdiscard) {
            // Replacing the completion criteria of a course where somebody concluded it: the conscious confirmation, the permission
            // of the full backup (its only way back), and nothing less.
            if (!$forcediscard) {
                throw new moodle_exception('error:discardnotconfirmed', 'local_miwlab');
            }
            self::require_capabilities($context, $course, replacer::FULL_BACKUP_CAPABILITIES);
        }
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $row = (object) [
            'restoreid' => \restore_controller::get_tempdir_name($course->id, $USER->id),
            'categoryid' => (int) $course->category,
            'courseid' => (int) $course->id,
            'mode' => self::MODE,
            'marker' => null,
            'expectedsignature' => $record->signature,
            'unchangedsince' => 0,
            'forcediscard' => $needsdiscard ? 1 : 0,
            'keepdays' => $keepdays,
            'previewid' => $record->previewid,
            'status' => restorer::STATUS_QUEUED,
            'progress' => 0,
            'timecreated' => time(),
            'timestarted' => 0,
            'timefinished' => 0,
        ];
        $row->id = $DB->insert_record('local_miwlab_restores', $row);
        $DB->set_field('local_miwlab_updates', 'status', self::USED, ['id' => $record->id]);
        $task = new \local_miwlab\task\restore_course();
        $task->set_userid($USER->id);
        $task->set_custom_data((object) ['id' => (int) $row->id, 'fileid' => 0]);
        \core\task\manager::queue_adhoc_task($task);
        return $row;
    }

    /**
     * Checks the package of the new activities against the list, before it is kept: exactly the activities and sections announced,
     * each in the section announced, and no activity of the course (it would be duplicated).
     *
     * @param \stored_file $file
     * @param stdClass $course
     * @param array $ops
     * @throws moodle_exception error:packagemismatch
     */
    protected static function inspect_package(\stored_file $file, stdClass $course, array $ops): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $tempdir = make_backup_temp_directory('miwlab-inspect-' . bin2hex(random_bytes(8)));
        try {
            restorer::extract($file, $tempdir, '');
            $xml = self::read_xml($tempdir . '/moodle_backup.xml');
            $bysectionid = [];
            $packed = [];
            foreach ($xml->information->contents->sections->section ?? [] as $s) {
                $dir = (string) $s->directory;
                if (!preg_match('~^sections/section_\d+$~', $dir)) {
                    throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'section directory');
                }
                $sx = self::read_xml($tempdir . '/' . $dir . '/section.xml');
                $bysectionid[(string) $s->sectionid] = (int) $sx->number;
            }
            foreach ($xml->information->contents->activities->activity ?? [] as $a) {
                $module = (string) $a->modulename;
                if ($module === 'qbank') {
                    continue;
                }
                $packed[] = ['module' => $module, 'title' => trim(html_entity_decode((string) $a->title)),
                    'number' => $bysectionid[(string) $a->sectionid] ?? -1];
            }
            $existing = array_map('intval', $DB->get_records_menu('course_sections', ['course' => $course->id], '', 'id, section'));
            $newnumbers = [];
            foreach ($ops['newsections'] as $n) {
                $newnumbers[$n['key']] = $n['number'];
            }
            $max = $existing ? max($existing) : 0;
            $expected = array_values($newnumbers);
            sort($expected);
            if ($expected !== ($expected ? range($max + 1, $max + count($expected)) : [])) {
                throw new moodle_exception(
                    'error:packagemismatch',
                    'local_miwlab',
                    '',
                    'the new sections are not the next numbers'
                );
            }
            $want = [];
            foreach ($ops['added'] as $a) {
                $number = is_int($a['section']) ? (int) $existing[$a['section']] : $newnumbers[$a['section']];
                $want[] = ['module' => $a['module'], 'title' => trim($a['name']), 'number' => $number];
            }
            $key = fn(array $x): string => $x['module'] . '|' . ($x['module'] === 'label' ? '' : $x['title']) . '|' . $x['number'];
            $a = array_map($key, $packed);
            $b = array_map($key, $want);
            sort($a);
            sort($b);
            if ($a !== $b) {
                throw new moodle_exception(
                    'error:packagemismatch',
                    'local_miwlab',
                    '',
                    'the activities are not the ones announced'
                );
            }
            $allowed = array_merge(array_values($existing), array_values($newnumbers));
            foreach ($bysectionid as $number) {
                if (!in_array($number, $allowed, true)) {
                    throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'a section that is not announced');
                }
            }
        } finally {
            fulldelete($tempdir);
        }
    }

    /**
     * An XML file of a package, read without the network and with a size limit.
     *
     * @param string $path
     * @return \SimpleXMLElement
     */
    protected static function read_xml(string $path): \SimpleXMLElement {
        if (!is_readable($path) || filesize($path) > 20 * 1024 * 1024) {
            throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'unreadable');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($path, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if (!$xml) {
            throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'unreadable');
        }
        return $xml;
    }

    /**
     * Deletes the previews that expired (and the packages kept for them).
     *
     * @param int|null $now
     */
    public static function cleanup(?int $now = null): void {
        global $DB;
        $now = $now ?? time();
        $old = $DB->get_records_select('local_miwlab_updates', 'timeexpires < :limit', ['limit' => $now - DAYSECS]);
        $fs = get_file_storage();
        foreach ($old as $r) {
            $context = \context_course::instance((int) $r->courseid, IGNORE_MISSING);
            if ($context) {
                $fs->delete_area_files($context->id, 'local_miwlab', self::PACKAGE_AREA, (int) $r->id);
            }
            $DB->delete_records('local_miwlab_updates', ['id' => $r->id]);
        }
    }
}
