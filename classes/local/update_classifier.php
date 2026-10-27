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

use stdClass;

/**
 * Sorts each operation of a safe update against the course AS IT IS NOW and the progress of its people, activity by activity
 * (1.9.0, issue 204). The same code makes the preview and, right before applying, checks it again.
 *
 * Groups: update (changes in place, nothing a person did is touched), add (new activities and sections, and what is hidden), skip
 * (it would touch a grade or a completion, or the plugin cannot be sure: left out, with the reason).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_classifier {
    /** Modules whose name, text, dates and completion are updated in place (the ones the Phase 0 of issue 204 tested). */
    const UPDATABLE = [
        'page', 'label', 'url', 'resource', 'folder', 'book', 'assign', 'forum', 'quiz', 'feedback', 'lesson', 'choice', 'glossary',
        'data', 'wiki', 'h5pactivity', 'scorm',
    ];

    /** Modules whose maximum grade is updated, with the function that rewrites their grade item. */
    const GRADE_FUNCTIONS = [
        'assign' => 'assign_grade_item_update',
        'quiz' => 'quiz_grade_item_update',
        'lesson' => 'lesson_grade_item_update',
        'h5pactivity' => 'h5pactivity_grade_item_update',
    ];

    /** Modules that put a grade in the gradebook once they exist (a new one changes the total of the course). */
    const GRADED_WHEN_NEW = ['assign', 'quiz', 'lesson', 'h5pactivity', 'scorm', 'workshop', 'data', 'glossary'];

    /**
     * Sorts every operation against the course as it is and the progress of each activity.
     *
     * @param stdClass $course
     * @param array $ops The normalised operations (update_operations::parse).
     * @param array $progress replacer::progress_by_activity()
     * @param int $enrolled People enrolled.
     * @return array The items, each [id, group, kind, ref, module, name, section, field, action, reason, people, warnings,
     *   suggestdelete].
     */
    public static function classify(stdClass $course, array $ops, array $progress, int $enrolled): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $modinfo = get_fast_modinfo($course->id);
        $items = [];
        $courseprogress = count($progress['course']);
        $people = fn(int $cmid): int => count($progress['activities'][$cmid] ?? []);
        $sectionname = function (int $sectionid) use ($modinfo, $course): string {
            $info = $modinfo->get_section_info_by_id($sectionid);
            return $info ? (string) get_section_name($course, $info) : '';
        };
        $add = function (array $item) use (&$items): void {
            $items[] = $item + ['reason' => '', 'people' => 0, 'warnings' => [], 'suggestdelete' => false, 'module' => '',
                'name' => '',
                'section' => '', 'field' => '', 'kept' => 0, 'choice' => '', 'canchoose' => false, 'needsdiscard' => false];
        };

        foreach ($ops['activities'] as $a) {
            $cm = $modinfo->get_cm($a['cmid']);
            if (!$cm || !empty($cm->deletioninprogress)) {
                $add(['id' => 'a:' . $a['cmid'], 'group' => 'skip', 'kind' => 'activity', 'ref' => (string) $a['cmid'],
                    'action' => 'skip',
                    'reason' => 'deleting']);
                continue;
            }
            $n = $people($cm->id);
            $base = ['kind' => 'activity', 'ref' => (string) $cm->id, 'module' => $cm->modname, 'name' => (string) $cm->name,
                'section' => $sectionname((int) $cm->section), 'people' => $n];
            $inplace = in_array($cm->modname, self::UPDATABLE, true);
            $row = $DB->get_record($cm->modname, ['id' => $cm->instance]);
            $emit = function (
                string $field,
                string $action,
                string $reason = '',
                array $warnings = []
            ) use (
                $add,
                $base,
                $cm
): void {
                $add($base + ['id' => 'a:' . $cm->id . ':' . $field, 'field' => $field,
                    'group' => $action === 'skip' ? 'skip' : 'update',
                    'action' => $action, 'reason' => $reason, 'warnings' => $warnings]);
            };
            if (array_key_exists('name', $a) && $cm->modname !== 'label' && $row && (string) $row->name !== $a['name']) {
                $inplace ? $emit('name', 'update') : $emit('name', 'skip', 'unknowntype');
            }
            if (array_key_exists('text', $a) && $row) {
                $field = $a['text']['field'];
                $valid = property_exists($row, $field) && ($field === 'content' ? $cm->modname === 'page' : true);
                if (!$valid) {
                    $emit('text', 'skip', 'unknowntype');
                } else if ((string) $row->$field !== $a['text']['html']) {
                    if (!$inplace) {
                        $emit('text', 'skip', 'unknowntype');
                    } else if (self::cites_new_file($cm, $field, $a['text']['html'])) {
                        $emit('text', 'skip', 'newfile');
                    } else {
                        $emit('text', 'update');
                    }
                }
            }
            if (array_key_exists('completion', $a) && self::completion_differs($cm, $row, $a['completion'])) {
                $missing = false;
                foreach ($a['completion']['type'] as $col => $unused) {
                    $missing = $missing || !$row || !property_exists($row, $col);
                }
                if (!$inplace || $missing) {
                    $emit('completion', 'skip', 'unknowntype');
                } else if ($n > 0) {
                    $emit('completion', 'skip', 'progress');
                } else {
                    $emit('completion', 'update');
                }
            }
            if (
                array_key_exists('availability', $a) && self::availability_differs(
                    (string) $cm->availability,
                    $a['availability']
                )
            ) {
                $emit('availability', 'update', '', $n > 0 ? ['restrictionwithprogress'] : []);
            }
            if (array_key_exists('visible', $a) && (bool) $cm->visible !== $a['visible']) {
                $emit('visible', 'update', '', $n > 0 ? ['visibilitywithprogress'] : []);
            }
            if (array_key_exists('dates', $a) && $row) {
                $allowed = update_operations::DATE_COLUMNS[$cm->modname] ?? [];
                $bad = array_diff(array_keys($a['dates']), $allowed);
                $changed = false;
                foreach ($a['dates'] as $col => $value) {
                    if (in_array($col, $allowed, true) && (int) $row->$col !== $value) {
                        $changed = true;
                    }
                }
                if ($bad) {
                    $emit('dates', 'skip', 'unknowntype');
                } else if ($changed) {
                    $emit('dates', 'update', '', $n > 0 ? ['dateswithprogress'] : []);
                }
            }
            if (array_key_exists('grademax', $a) && $row) {
                if (!isset(self::GRADE_FUNCTIONS[$cm->modname]) || !property_exists($row, 'grade')) {
                    $emit('grademax', 'skip', 'unknowntype');
                } else if (abs((float) $row->grade - $a['grademax']) > 0.000001) {
                    $n > 0 ? $emit('grademax', 'skip', 'progress') : $emit('grademax', 'update');
                }
            }
        }

        foreach ($ops['added'] as $x) {
            $warn = ($enrolled > 0 && in_array($x['module'], self::GRADED_WHEN_NEW, true)) ? ['newgraded'] : [];
            $add(['id' => 'n:' . $x['key'], 'group' => 'add', 'kind' => 'added', 'ref' => $x['key'], 'module' => $x['module'],
                'name' => $x['name'], 'section' => is_int($x['section']) ? $sectionname($x['section']) : '', 'action' => 'add',
                'warnings' => $warn]);
        }
        foreach ($ops['retired'] as $r) {
            $cm = $modinfo->get_cm($r['cmid']);
            if (!$cm || !empty($cm->deletioninprogress)) {
                $add(['id' => 'r:' . $r['cmid'], 'group' => 'skip', 'kind' => 'retired', 'ref' => (string) $r['cmid'],
                    'action' => 'skip',
                    'reason' => 'deleting']);
                continue;
            }
            if (!$cm->visible) {
                continue;
            }
            $n = $people($cm->id);
            $add(['id' => 'r:' . $cm->id, 'group' => 'add', 'kind' => 'retired', 'ref' => (string) $cm->id,
                'module' => $cm->modname,
                'name' => (string) $cm->name, 'section' => $sectionname((int) $cm->section), 'action' => 'hide', 'people' => $n,
                'suggestdelete' => $n === 0]);
        }
        foreach ($ops['sections'] as $s) {
            $rec = $DB->get_record('course_sections', ['id' => $s['id']]);
            $base = ['kind' => 'section', 'ref' => (string) $s['id'], 'section' => $sectionname((int) $s['id']),
                'name' => $sectionname((int) $s['id'])];
            if (array_key_exists('name', $s) && (string) $rec->name !== $s['name']) {
                $add($base + ['id' => 's:' . $s['id'] . ':name', 'field' => 'name', 'group' => 'update', 'action' => 'update']);
            }
            if (array_key_exists('summary', $s) && (string) $rec->summary !== $s['summary']) {
                $new = self::cites_new_section_file((int) $course->id, (int) $s['id'], $s['summary']);
                $add($base + ['id' => 's:' . $s['id'] . ':summary', 'field' => 'summary', 'group' => $new ? 'skip' : 'update',
                    'action' => $new ? 'skip' : 'update', 'reason' => $new ? 'newfile' : '']);
            }
        }
        foreach ($ops['newsections'] as $s) {
            $add(['id' => 'ns:' . $s['key'], 'group' => 'add', 'kind' => 'newsection', 'ref' => $s['key'], 'name' => $s['name'],
                'section' => $s['name'], 'action' => 'add']);
        }
        foreach ($ops['retiredsections'] as $s) {
            $rec = $DB->get_record('course_sections', ['id' => $s['id']]);
            if ((int) $rec->section === 0) {
                $add(['id' => 'rs:' . $s['id'], 'group' => 'skip', 'kind' => 'retiredsection', 'ref' => (string) $s['id'],
                    'name' => $sectionname((int) $s['id']), 'section' => $sectionname((int) $s['id']), 'action' => 'skip',
                    'reason' => 'sectionzero']);
            } else if ((int) $rec->visible === 1) {
                $add(['id' => 'rs:' . $s['id'], 'group' => 'add', 'kind' => 'retiredsection', 'ref' => (string) $s['id'],
                    'name' => $sectionname((int) $s['id']), 'section' => $sectionname((int) $s['id']), 'action' => 'hide']);
            }
        }
        foreach ($ops['order'] as $i => $o) {
            $add(['id' => 'o:' . $i, 'group' => 'update', 'kind' => 'order', 'ref' => (string) $o['section'],
                'section' => is_int($o['section']) ? $sectionname($o['section']) : '', 'field' => 'position',
                    'action' => 'update']);
        }
        if ($ops['sectionorder']) {
            $add(['id' => 'so', 'group' => 'update', 'kind' => 'order', 'ref' => 'sections', 'field' => 'sectionorder',
                'action' => 'update']);
        }
        $cs = $ops['coursesettings'];
        if (isset($cs['completion'])) {
            $lost = self::completed_people((int) $course->id);
            if (self::criteria_differ((int) $course->id, $cs['completion'])) {
                $replace = $cs['completion']['choice'] === 'replace';
                $add(['id' => 'cs:completion', 'group' => 'choice', 'kind' => 'coursesetting', 'ref' => 'completion',
                    'field' => 'completion',
                    'name' => '', 'action' => $replace ? 'update' : 'keep', 'choice' => $cs['completion']['choice'],
                        'people' => $lost,
                    'needsdiscard' => $lost > 0, 'warnings' => $lost > 0 && $replace ? ['courseconfirm'] : []]);
            }
        }
        if (isset($cs['gradebook'])) {
            $effect = self::gradebook_effect((int) $course->id);
            if (self::category_differs((int) $course->id, $cs['gradebook']['category'])) {
                $replace = $cs['gradebook']['choice'] === 'replace';
                $add(['id' => 'cs:gradebook', 'group' => 'choice', 'kind' => 'coursesetting', 'ref' => 'gradebook',
                    'field' => 'gradebook',
                    'action' => $replace ? 'update' : 'keep', 'choice' => $cs['gradebook']['choice'],
                        'people' => $effect['recalculated'],
                    'kept' => $effect['overridden'], 'needsdiscard' => false]);
            }
        }
        foreach (($cs['blocks'] ?? []) as $i => $b) {
            if (
                $b['proposal'] === 'add' && $DB->record_exists('block_instances', ['blockname' => $b['name'],
                    'parentcontextid' => \context_course::instance($course->id)->id])
            ) {
                // Already on the course page: nothing to choose.
                continue;
            }
            $apply = $b['choice'] === 'apply';
            $reason = '';
            if ($b['proposal'] !== 'add') {
                $reason = self::block_obstacle((int) $b['instanceid'], $b['name']);
            }
            $row = ['id' => 'cs:block:' . $i, 'group' => 'choice', 'kind' => 'coursesetting',
                'ref' => (string) ($b['instanceid'] ?? $b['name']),
                'field' => 'block:' . $b['proposal'], 'module' => $b['name'], 'name' => $b['name'], 'choice' => $b['choice'],
                'canchoose' => $reason === '', 'needsdiscard' => false];
            if ($apply && $reason !== '') {
                $add($row + ['action' => 'skip', 'reason' => $reason]);
            } else {
                $add($row + ['action' => $apply ? 'update' : 'keep', 'reason' => $reason]);
            }
        }
        return $items;
    }

    /** Core and known blocks whose instance keeps no data of a person by itself (comments are looked for). */
    const BLOCKS_WITHOUT_PEOPLE = ['html', 'activity_modules', 'calendar_month', 'calendar_upcoming', 'course_summary',
        'recent_activity',
        'section_links', 'social_activities', 'completion_progress', 'news_items', 'search_forums', 'rss_client', 'tags',
            'comments'];

    /**
     * Why a block cannot be replaced or removed: persondata (it holds a comment or an answer of somebody) or unknownblock; ''
     * when it can.
     *
     * @param int $instanceid
     * @param string $name
     * @return string
     */
    public static function block_obstacle(int $instanceid, string $name): string {
        global $DB;
        $context = \context_block::instance($instanceid, IGNORE_MISSING);
        if ($context && $DB->record_exists('comments', ['contextid' => $context->id])) {
            return 'persondata';
        }
        return in_array($name, self::BLOCKS_WITHOUT_PEOPLE, true) ? '' : 'unknownblock';
    }

    /**
     * How many people concluded the course (they lose it if its completion criteria are replaced).
     *
     * @param int $courseid
     * @return int
     */
    public static function completed_people(int $courseid): int {
        global $DB;
        $account = (int) get_config('local_miwlab', 'accountid');
        return (int) $DB->count_records_select(
            'course_completions',
            'course = :c AND timecompleted IS NOT NULL AND userid <> :a',
            ['c' => $courseid, 'a' => $account]
        );
    }

    /**
     * Whether the proposed criteria differ from the ones the course has.
     *
     * @param int $courseid
     * @param array $want
     * @return bool
     */
    public static function criteria_differ(int $courseid, array $want): bool {
        global $DB;
        $have = $DB->get_records('course_completion_criteria', ['course' => $courseid]);
        $ids = [];
        foreach ($have as $c) {
            if ((int) $c->criteriatype !== 7) {
                return true;
            }
            $ids[] = (int) $c->courseinstance;
        }
        $wantids = array_map(fn($k) => (int) $k['courseinstance'], $want['criteria']);
        sort($ids);
        sort($wantids);
        if ($ids !== $wantids) {
            return true;
        }
        if (!$ids) {
            return false;
        }
        $method = $DB->get_record('course_completion_aggr_methd', ['course' => $courseid, 'criteriatype' => null]);
        return !$method || (int) $method->method !== (int) $want['aggregation'];
    }

    /**
     * Whether the proposed settings differ from the ones of the root category of the gradebook.
     *
     * @param int $courseid
     * @param array $want
     * @return bool
     */
    public static function category_differs(int $courseid, array $want): bool {
        global $DB;
        $item = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course'], 'iteminstance');
        $cat = $item ? $DB->get_record('grade_categories', ['id' => $item->iteminstance]) : false;
        if (!$cat) {
            return true;
        }
        foreach ($want as $k => $v) {
            if ((int) $cat->$k !== $v) {
                return true;
            }
        }
        return false;
    }

    /**
     * What replacing the gradebook of the course does to people: grades of activities stay; the total is recalculated for those who
     * have a grade and no total typed by hand; the total typed by hand is kept (counts only).
     *
     * @param int $courseid
     * @return array ['recalculated' => int, 'overridden' => int]
     */
    public static function gradebook_effect(int $courseid): array {
        global $DB;
        $overridden = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT gg.userid) FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :c AND gi.itemtype = 'course' AND gg.overridden > 0",
            ['c' => $courseid]
        );
        $recalculated = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT gg.userid) FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :c AND gi.itemtype NOT IN ('course', 'category') AND gg.finalgrade IS NOT NULL
                AND gg.userid NOT IN (SELECT g2.userid FROM {grade_grades} g2 JOIN {grade_items} i2 ON i2.id = g2.itemid
                                       WHERE i2.courseid = :c2 AND i2.itemtype = 'course' AND g2.overridden > 0)",
            ['c' => $courseid, 'c2' => $courseid]
        );
        return ['recalculated' => $recalculated, 'overridden' => $overridden];
    }

    /**
     * Whether the completion asked differs from what the activity has.
     *
     * @param \cm_info $cm
     * @param stdClass|false $row The module instance.
     * @param array $want
     * @return bool
     */
    public static function completion_differs(\cm_info $cm, $row, array $want): bool {
        $want = self::normalize_completion((int) $cm->id, $want);
        $have = [
            'completion' => (int) $cm->completion,
            'completionview' => (int) $cm->completionview,
            'completionexpected' => (int) $cm->completionexpected,
            'completiongradeitemnumber' => $cm->completiongradeitemnumber === null ? null : (int) $cm->completiongradeitemnumber,
            'completionpassgrade' => (int) ($cm->completionpassgrade ?? 0),
        ];
        foreach ($have as $col => $v) {
            if (array_key_exists($col, $want) && $want[$col] !== $v) {
                return true;
            }
        }
        foreach ($want['type'] as $col => $v) {
            if (!$row || !property_exists($row, $col) || (int) $row->$col !== $v) {
                return true;
            }
        }
        return false;
    }

    /**
     * The completion rule as it can exist: "receive a grade" and "receive a passing grade" need a grade item in the activity;
     * without
     * one the rule drops them (as the package of the MiwLab does for an activity without grade items).
     *
     * @param int $cmid
     * @param array $want
     * @return array
     */
    public static function normalize_completion(int $cmid, array $want): array {
        global $DB;
        if (($want['completiongradeitemnumber'] ?? null) === null && empty($want['completionpassgrade'])) {
            return $want;
        }
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $hasitem = $DB->record_exists('grade_items', ['courseid' => $cm->course, 'itemtype' => 'mod', 'itemmodule' => $cm->modname,
            'iteminstance' => $cm->instance, 'itemnumber' => 0]);
        if (!$hasitem) {
            $want['completiongradeitemnumber'] = null;
            $want['completionpassgrade'] = 0;
        }
        return $want;
    }

    /**
     * Whether the access restriction the MiwLab asks differs from the one the activity has.
     *
     * @param string $have The availability JSON of the activity ('' none).
     * @param string|null $want
     * @return bool
     */
    public static function availability_differs(string $have, ?string $want): bool {
        $norm = function (?string $json): string {
            if ($json === null || $json === '') {
                return '';
            }
            $d = json_decode($json, true);
            return $d === null ? $json : json_encode($d);
        };
        return $norm($have) !== $norm($want);
    }

    /**
     * Whether an HTML cites (@@PLUGINFILE@@/name) a file the activity does not have: the plugin cannot bring new files in place.
     *
     * @param \cm_info $cm
     * @param string $field intro or content.
     * @param string $html
     * @return bool
     */
    protected static function cites_new_file(\cm_info $cm, string $field, string $html): bool {
        $names = self::cited_files($html);
        if (!$names) {
            return false;
        }
        $fs = get_file_storage();
        $have = [];
        foreach ([$field, 'intro', 'content'] as $area) {
            foreach ($fs->get_area_files($cm->context->id, 'mod_' . $cm->modname, $area, false, 'id', false) as $f) {
                $have[$f->get_filepath() . $f->get_filename()] = true;
            }
        }
        foreach ($names as $name) {
            if (!isset($have['/' . $name])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the text of a section cites a file the section does not have.
     *
     * @param int $courseid
     * @param int $sectionid
     * @param string $html
     * @return bool
     */
    protected static function cites_new_section_file(int $courseid, int $sectionid, string $html): bool {
        $names = self::cited_files($html);
        if (!$names) {
            return false;
        }
        $fs = get_file_storage();
        $have = [];
        foreach (
            $fs->get_area_files(
                \context_course::instance($courseid)->id,
                'course',
                'section',
                $sectionid,
                'id',
                false
            ) as $f
        ) {
            $have['/' . $f->get_filename()] = true;
        }
        foreach ($names as $name) {
            if (!isset($have['/' . $name])) {
                return true;
            }
        }
        return false;
    }

    /**
     * The file names an HTML cites by @@PLUGINFILE@@/<name>.
     *
     * @param string $html
     * @return string[]
     */
    public static function cited_files(string $html): array {
        if (strpos($html, '@@PLUGINFILE@@') === false) {
            return [];
        }
        preg_match_all('~@@PLUGINFILE@@/([^"\'\s?#)<>]+)~', $html, $m);
        return array_values(array_unique(array_map('rawurldecode', $m[1])));
    }
}
