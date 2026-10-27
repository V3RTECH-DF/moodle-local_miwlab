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
use restore_controller;
use stdClass;

/**
 * Applies a preview of a safe update, in the background (1.9.0, issue 204). It applies EXACTLY what the preview showed: what the
 * preview left out stays out, and what was safe then but is not any more (somebody did the activity in between) is left out too.
 * Before touching anything it takes the backup without people; during the work it keeps an undo journal; a failure puts every
 * row back
 * and the course signature is the one from before (the backup is only the second line, and says where it is).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_runner {
    /**
     * Only for PHPUnit (ignored otherwise): called with the name of each step right before it runs, so that a failure in the
     * middle can
     * be simulated by throwing.
     *
     * @var callable|null
     */
    public static $stephook = null;

    /**
     * Runs a queued application. Never throws: the result, or the failure with the reason, is recorded.
     *
     * @param stdClass $record The restore record, already running.
     */
    public static function run(stdClass $record): void {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $journal = null;
        $backup = null;
        $touched = false;
        $course = null;
        $context = null;
        $backupname = '';
        $expires = 0;
        $preview = null;
        try {
            $course = replacer::course((int) $record->courseid);
            $context = \context_course::instance($course->id);
            $full = !empty($record->forcediscard);
            updater::require_capabilities($context, $course, $full ? replacer::FULL_BACKUP_CAPABILITIES : []);
            $preview = $DB->get_record('local_miwlab_updates', ['previewid' => (string) $record->previewid]);
            if (!$preview || (int) $preview->courseid !== (int) $course->id) {
                throw new moodle_exception('error:previewnotfound', 'local_miwlab');
            }
            // The course must be as it was when the preview was made (the people's data is not part of the signature; it is
            // checked below).
            replacer::require_unchanged($course->id, $preview->signature, 0);
            $ops = json_decode($preview->operations, true);
            $previewitems = json_decode($preview->items, true);
            $plan = self::reconcile($course, $ops, $previewitems, $full);

            // 1. The backup of the course as it is, without people: without it, nothing changes.
            $backup = replacer::backup($course->id, $full);
            $backupname = self::backup_name($course, $full);
            $expires = 0;
            if ($full) {
                $days = fullcopies::require_valid_days((int) ($record->keepdays ?? fullcopies::DEFAULT_DAYS));
                $expires = time() + $days * DAYSECS;
            }
            $DB->set_field('local_miwlab_restores', 'backupfilename', $backupname, ['id' => $record->id]);
            // Somebody may have done something while the backup ran: the same check, with the same rule.
            $plan = self::reconcile($course, $ops, $previewitems, $full);

            // 2. The work, with the journal.
            $journal = new update_journal((int) $course->id);
            $appliers = new update_appliers($course, $journal);
            $touched = true;
            $keys = [];
            $sections = [];
            if ($ops['added'] || $ops['newsections']) {
                self::step('restore');
                [$keys, $sections] = self::restore_additions($course, $record, $preview, $ops, $journal);
            }
            self::apply($course, $ops, $plan, $appliers, $keys, $sections);
            self::finish_order($course, $ops, $keys, $sections);
            self::step('finish');
            rebuild_course_cache($course->id, true);
            \cache_helper::purge_by_event('changesincourse');
            $state = replacer::course_state((int) $course->id);
            replacer::keep_backup($context, $backupname, $backup, $expires);
            $backup = null;
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'fullbackup' => $expires > 0 ? 1 : 0,
                'backupexpires' => $expires,
                'updateresult' => json_encode(self::result($plan, $state)),
                'status' => restorer::STATUS_FINISHED,
                'progress' => 1,
                'timefinished' => time(),
            ]);
        } catch (\Throwable $e) {
            $code = $e instanceof moodle_exception ? substr($e->errorcode, 0, 100) : 'unexpected';
            $reason = restorer::reason($e);
            $rolledback = 0;
            if ($touched && $journal) {
                try {
                    $journal->undo($course);
                    $state = replacer::course_state((int) $course->id);
                    if ($preview && $state['signature'] === $preview->signature) {
                        $rolledback = 1;
                        $code = 'error:updatefailed';
                    } else {
                        $code = 'error:updaterollbackincomplete';
                    }
                } catch (\Throwable $rollback) {
                    $code = 'error:updaterollbackincomplete';
                    $reason .= ' / ' . restorer::reason($rollback);
                }
            }
            try {
                if ($backup && $touched && $context) {
                    replacer::keep_backup($context, $backupname, $backup, $expires);
                } else if ($backup) {
                    $backup->delete();
                    $DB->set_field('local_miwlab_restores', 'backupfilename', null, ['id' => $record->id]);
                }
            } catch (\Throwable $ignored) {
                $reason .= ' / ' . restorer::reason($ignored);
            }
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'status' => restorer::STATUS_FAILED,
                'rolledback' => $rolledback,
                'errorcode' => $code,
                'errormessage' => \core_text::substr($reason, 0, 2000),
                'timefinished' => time(),
            ]);
        } finally {
            if ($preview && $context) {
                get_file_storage()->delete_area_files($context->id, 'local_miwlab', updater::PACKAGE_AREA, (int) $preview->id);
            }
        }
    }

    /**
     * Calls the hook of the tests at a step of the application (nothing in production).
     *
     * @param string $name
     */
    protected static function step(string $name): void {
        if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && self::$stephook) {
            call_user_func(self::$stephook, $name);
        }
    }

    /**
     * The name of the backup made before an update, with or without the people.
     *
     * @param stdClass $course
     * @param bool $full
     * @return string
     */
    public static function backup_name(stdClass $course, bool $full): string {
        return 'miwlab-before-update-course-' . $course->id . '-' . date('Ymd-Hi') . ($full ? '-full' : '-nu') . '.mbz';
    }

    /**
     * What will be applied: the items of the preview that were safe then and are still safe now. Everything else gets its
     * result here.
     *
     * @param stdClass $course
     * @param array $ops
     * @param array $previewitems
     * @param bool $full The full backup is being made (the person confirmed the discard).
     * @return array ['apply' => [id => true], 'results' => [id => [status, reason, ...]]]
     */
    public static function reconcile(stdClass $course, array $ops, array $previewitems, bool $full): array {
        $people = replacer::people((int) $course->id);
        $progress = replacer::progress_by_activity((int) $course->id);
        $now = [];
        foreach (update_classifier::classify($course, $ops, $progress, $people['enrolled']) as $i) {
            $now[$i['id']] = $i;
        }
        $apply = [];
        $results = [];
        foreach ($previewitems as $p) {
            $id = $p['id'];
            $base = ['kind' => $p['kind'], 'module' => $p['module'], 'name' => $p['name'], 'section' => $p['section'],
                'field' => $p['field']];
            if ($p['action'] === 'skip') {
                $results[$id] = $base + ['status' => 'skipped', 'reason' => $p['reason']];
            } else if ($p['action'] === 'keep') {
                $results[$id] = $base + ['status' => 'kept', 'reason' => ''];
            } else if (!isset($now[$id])) {
                // Already as asked (somebody did it by hand in between): nothing to do.
                $results[$id] = $base + ['status' => 'unchanged', 'reason' => ''];
            } else if ($now[$id]['action'] === 'skip') {
                // It was safe in the preview and is not any more: somebody did the activity in between (the reason the person
                // sees is that one).
                $was = $now[$id]['reason'];
                $reason = $was === 'progress' || $was === '' ? 'progresschanged' : $was;
                $results[$id] = $base + ['status' => 'skipped', 'reason' => $reason];
            } else if (!empty($now[$id]['needsdiscard']) && $p['action'] === 'update' && !$full) {
                // The completion of the course: it only goes with the conscious confirmation and the full backup.
                $results[$id] = $base + ['status' => 'skipped', 'reason' => 'progresschanged'];
            } else {
                $apply[$id] = true;
                $results[$id] = $base + ['status' => 'planned', 'reason' => ''];
            }
        }
        return ['apply' => $apply, 'results' => $results];
    }

    /**
     * Restores the package of the new activities and sections into the course, only adding, and finds what was created.
     *
     * @param stdClass $course
     * @param stdClass $record
     * @param stdClass $preview
     * @param array $ops
     * @param update_journal $journal
     * @return array [key => cmid, key => section id]
     */
    protected static function restore_additions(
        stdClass $course,
        stdClass $record,
        stdClass $preview,
        array $ops,
        update_journal $journal
    ): array {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $context = \context_course::instance($course->id);
        $file = get_file_storage()->get_file(
            $context->id,
            'local_miwlab',
            updater::PACKAGE_AREA,
            (int) $preview->id,
            '/',
            'package.mbz'
        );
        if (!$file) {
            throw new moodle_exception('error:draftfilenotfound', 'local_miwlab');
        }
        $tempdir = make_backup_temp_directory($record->restoreid);
        try {
            restorer::extract($file, $tempdir, '');
            $controller = null;
            foreach ([true, false] as $precheckonly) {
                $controller = new restore_controller(
                    $record->restoreid,
                    $course->id,
                    backup::INTERACTIVE_NO,
                    backup::MODE_GENERAL,
                    (int) $USER->id,
                    backup::TARGET_EXISTING_ADDING
                );
                $plan = $controller->get_plan();
                $settings = ['users' => 0, 'role_assignments' => 0, 'userscompletion' => 0, 'overwrite_conf' => 0, 'blocks' => 0,
                    'badges' => 0, 'comments' => 0, 'logs' => 0, 'grade_histories' => 0];
                if ((int) $course->startdate > 0) {
                    $settings['course_startdate'] = (int) $course->startdate;
                }
                foreach ($settings as $name => $value) {
                    if ($plan->setting_exists($name) && $plan->get_setting($name)->get_status() == base_setting::NOT_LOCKED) {
                        $plan->get_setting($name)->set_value($value);
                    }
                }
                if (!$controller->execute_precheck($precheckonly)) {
                    $results = $controller->get_precheck_results();
                    if (!empty($results['errors'])) {
                        $controller->destroy();
                        throw new moodle_exception('error:precheck', 'local_miwlab', '', implode(' ', $results['errors']));
                    }
                }
                if (!$precheckonly) {
                    $controller->set_progress(new \core\progress\db_updater($record->id, 'local_miwlab_restores', 'progress'));
                    $controller->execute_plan();
                }
                $controller->destroy();
            }
        } finally {
            fulldelete($tempdir);
        }
        // What the restore created, found by what was there before.
        $newcms = [];
        foreach ($DB->get_records('course_modules', ['course' => $course->id], 'id') as $cm) {
            if (!in_array((int) $cm->id, $journal->cm_ids_before(), true)) {
                $newcms[] = $cm;
            }
        }
        $keys = [];
        $modinfo = get_fast_modinfo($course->id);
        foreach ($ops['added'] as $a) {
            foreach ($newcms as $i => $cm) {
                $info = $modinfo->get_cm((int) $cm->id);
                $samename = $info && trim((string) $info->name) === trim($a['name']);
                $same = $info && $info->modname === $a['module'] && ($a['module'] === 'label' || $samename);
                if ($same) {
                    $keys[$a['key']] = (int) $cm->id;
                    unset($newcms[$i]);
                    break;
                }
            }
            if (!isset($keys[$a['key']])) {
                throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'an announced activity did not arrive');
            }
        }
        foreach ($newcms as $cm) {
            $info = $modinfo->get_cm((int) $cm->id);
            if (!$info || $info->modname !== 'qbank') {
                throw new moodle_exception(
                    'error:packagemismatch',
                    'local_miwlab',
                    '',
                    'an activity that was not announced arrived'
                );
            }
        }
        $sections = [];
        foreach ($ops['newsections'] as $n) {
            $row = $DB->get_record('course_sections', ['course' => $course->id, 'section' => $n['number']]);
            if (!$row || in_array((int) $row->id, $journal->section_ids_before(), true)) {
                throw new moodle_exception('error:packagemismatch', 'local_miwlab', '', 'an announced section did not arrive');
            }
            $sections[$n['key']] = (int) $row->id;
        }
        return [$keys, $sections];
    }

    /**
     * Resolves an id or a key to the id.
     *
     * @param int|string $ref
     * @param array $keys
     * @return int
     */
    protected static function resolve($ref, array $keys): int {
        return is_int($ref) ? $ref : (int) ($keys[$ref] ?? 0);
    }

    /**
     * The field-by-field work of the items that are going to be applied.
     *
     * @param stdClass $course
     * @param array $ops
     * @param array $plan
     * @param update_appliers $appliers
     * @param array $keys
     * @param array $sections
     */
    protected static function apply(
        stdClass $course,
        array $ops,
        array &$plan,
        update_appliers $appliers,
        array $keys,
        array $sections
    ): void {
        $go = fn(string $id): bool => isset($plan['apply'][$id]);
        $done = function (string $id, string $status) use (&$plan): void {
            $plan['results'][$id]['status'] = $status;
        };
        foreach ($ops['activities'] as $a) {
            $cmid = $a['cmid'];
            $pairs = [
                'name' => fn() => $appliers->name($cmid, $a['name']),
                'text' => fn() => $appliers->text($cmid, $a['text']['field'], $a['text']['html']),
                'completion' => fn() => $appliers->completion($cmid, $a['completion']),
                'availability' => fn() => $appliers->availability($cmid, $a['availability']),
                'visible' => fn() => $appliers->visible($cmid, $a['visible']),
                'dates' => fn() => $appliers->dates($cmid, $a['dates'] ?? []),
                'grademax' => fn() => $appliers->grademax($cmid, $a['grademax'] ?? 0.0),
            ];
            foreach ($pairs as $field => $fn) {
                $id = 'a:' . $cmid . ':' . $field;
                if ($go($id)) {
                    self::step($id);
                    $fn();
                    $done($id, 'updated');
                }
            }
        }
        foreach ($ops['added'] as $a) {
            $id = 'n:' . $a['key'];
            if ($go($id)) {
                $done($id, 'added');
                $plan['results'][$id]['ref'] = $keys[$a['key']] ?? 0;
            }
        }
        foreach ($ops['newsections'] as $n) {
            $id = 'ns:' . $n['key'];
            if ($go($id)) {
                $done($id, 'added');
            }
        }
        foreach ($ops['retired'] as $r) {
            $id = 'r:' . $r['cmid'];
            if ($go($id)) {
                self::step($id);
                $appliers->hide($r['cmid']);
                $done($id, 'hidden');
            }
        }
        foreach ($ops['sections'] as $s) {
            foreach (['name', 'summary'] as $field) {
                $id = 's:' . $s['id'] . ':' . $field;
                if ($go($id) && array_key_exists($field, $s)) {
                    self::step($id);
                    $appliers->section($s['id'], [$field => $s[$field]]);
                    $done($id, 'updated');
                }
            }
        }
        foreach ($ops['retiredsections'] as $s) {
            $id = 'rs:' . $s['id'];
            if ($go($id)) {
                self::step($id);
                $appliers->hide_section($s['id']);
                $done($id, 'hidden');
            }
        }
        $cs = $ops['coursesettings'];
        if ($go('cs:completion')) {
            self::step('cs:completion');
            $appliers->completion_criteria($cs['completion']);
            $done('cs:completion', 'updated');
        }
        if ($go('cs:gradebook')) {
            self::step('cs:gradebook');
            $appliers->gradebook($cs['gradebook']['category']);
            $done('cs:gradebook', 'updated');
        }
        foreach (($cs['blocks'] ?? []) as $i => $b) {
            if ($go('cs:block:' . $i)) {
                self::step('cs:block:' . $i);
                $appliers->block($b);
                $done('cs:block:' . $i, $b['proposal'] === 'add' ? 'added' : ($b['proposal'] === 'remove' ? 'hidden' : 'updated'));
            }
        }
    }

    /**
     * The order of the activities in the sections and of the sections, as asked (the ones that only exist in Moodle keep their
     * places).
     *
     * @param stdClass $course
     * @param array $ops
     * @param array $keys
     * @param array $sections
     */
    protected static function finish_order(stdClass $course, array $ops, array $keys, array $sections): void {
        global $DB;
        $listedall = [];
        foreach ($ops['order'] as $o) {
            foreach ($o['items'] as $item) {
                $listedall[] = self::resolve($item, $keys);
            }
        }
        foreach ($ops['order'] as $o) {
            $sectionid = self::resolve($o['section'], $sections);
            $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $course->id], '*', MUST_EXIST);
            $current = array_values(array_filter(array_map('intval', explode(',', (string) $section->sequence))));
            $listed = array_values(array_filter(array_map(fn($i) => self::resolve($i, $keys), $o['items'])));
            $final = self::slots($current, $listed, $listedall);
            if ($final === $current) {
                continue;
            }
            self::step('order:' . $sectionid);
            for ($i = count($final) - 1; $i >= 0; $i--) {
                if ($i === count($final) - 1) {
                    compat::move_cm_to_end((int) $course->id, $final[$i], $sectionid);
                } else {
                    compat::move_cm_before((int) $course->id, $final[$i], $final[$i + 1]);
                }
            }
        }
        if ($ops['sectionorder']) {
            self::step('sectionorder');
            $listed = array_values(array_filter(array_map(fn($i) => self::resolve($i, $sections), $ops['sectionorder'])));
            $rows = $DB->get_records('course_sections', ['course' => $course->id], 'section', 'id, section');
            $current = array_map(fn($r) => (int) $r->id, array_values($rows));
            $final = self::slots($current, $listed, $listed);
            if ($final[0] !== $current[0]) {
                throw new moodle_exception('error:invalidoperations', 'local_miwlab', '', 'the general section does not move');
            }
            foreach ($final as $position => $id) {
                $row = $DB->get_record('course_sections', ['id' => $id], '*', MUST_EXIST);
                compat::move_section($course, $row, $position);
            }
        }
    }

    /**
     * The final order of a list: the elements that are not listed anywhere keep their places; the listed ones fill the other
     * places in
     * the order asked (the surplus goes to the end).
     *
     * @param int[] $current
     * @param int[] $listed What goes into this list, in order.
     * @param int[] $listedanywhere Everything that some list asks to place.
     * @return int[]
     */
    public static function slots(array $current, array $listed, array $listedanywhere): array {
        $final = [];
        $next = 0;
        foreach ($current as $id) {
            if (!in_array($id, $listedanywhere, true)) {
                $final[] = $id;
            } else if ($next < count($listed)) {
                $final[] = $listed[$next++];
            }
        }
        while ($next < count($listed)) {
            $final[] = $listed[$next++];
        }
        return $final;
    }

    /**
     * The result recorded for the MiwLab: item by item, what was done, and the state of the course after.
     *
     * @param array $plan
     * @param array $state
     * @return array
     */
    protected static function result(array $plan, array $state): array {
        $items = [];
        $counts = ['updated' => 0, 'added' => 0, 'hidden' => 0, 'skipped' => 0, 'kept' => 0, 'unchanged' => 0];
        foreach ($plan['results'] as $id => $r) {
            // An item still "planned" had nothing to do on its own (a new activity is brought by the package).
            $status = $r['status'] === 'planned' ? 'updated' : $r['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $items[] = array_merge($r, ['id' => (string) $id, 'status' => $status]);
        }
        return ['items' => $items, 'counts' => $counts, 'signature' => $state['signature']];
    }
}
