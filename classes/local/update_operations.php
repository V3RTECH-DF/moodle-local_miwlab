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

/**
 * The list of operations of a safe update (1.9.0, issue 204): read, validated and normalised. Fails closed: a field it does not
 * know, a value of the wrong kind or an id that is not of THIS course refuses the whole list, before anything is looked at.
 *
 * Shape (JSON): activities[] {cmid, name?, text? {field, html}, completion? {completion, completionview, completionexpected,
 * completiongradeitemnumber, completionpassgrade, type {column: int}}, availability? (JSON string or null), visible?, dates?
 * {column: int}, grademax?}, added[] {key, module, name, section (section id, or the key of a new section)}, retired[] {cmid},
 * sections[] {id, name?, summary?}, newsections[] {key, number, name}, retiredsections[] {id}, order[] {section, items[]},
 * sectionorder[], coursesettings {completion? {choice, aggregation, criteria[] {type 7, courseinstance}}, gradebook? {choice,
 * category
 {aggregation, aggregateonlygraded, keephigh, droplow}}, blocks[] {proposal add|replace|remove, choice keep|apply, name,
 instanceid?,
 config?}}: what is course-wide. "keep" is the default and touches nothing; the choice is the one of the person who sends.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_operations {
    /** Biggest list accepted, in bytes. */
    const MAX_BYTES = 4194304;

    /** @var string[] Keys of the list. */
    const TOP = [
        'activities', 'added', 'retired', 'sections', 'newsections', 'retiredsections', 'order', 'sectionorder', 'coursesettings',
    ];

    /** @var string[] Keys of an activity operation. */
    const ACTIVITY = ['cmid', 'name', 'text', 'completion', 'availability', 'visible', 'dates', 'grademax'];

    /** @var string[] Module-level completion columns of an activity. */
    const COMPLETION = ['completion', 'completionview', 'completionexpected', 'completiongradeitemnumber', 'completionpassgrade'];

    /** @var string[] Grade category settings a course-wide gradebook operation may set. */
    const CATEGORY_SETTINGS = ['aggregation', 'aggregateonlygraded', 'keephigh', 'droplow'];

    /**
     * Date columns an operation may change, by module (the module table; refreshed in the calendar after).
     */
    const DATE_COLUMNS = [
        'assign' => ['allowsubmissionsfromdate', 'duedate', 'cutoffdate', 'gradingduedate'],
        'quiz' => ['timeopen', 'timeclose'],
        'forum' => ['duedate', 'cutoffdate'],
        'lesson' => ['available', 'deadline'],
        'choice' => ['timeopen', 'timeclose'],
        'feedback' => ['timeopen', 'timeclose'],
        'data' => ['timeavailablefrom', 'timeavailableto', 'timeviewfrom', 'timeviewto'],
        'scorm' => ['timeopen', 'timeclose'],
    ];

    /**
     * Reads, validates and normalises the list for a course.
     *
     * @param int $courseid
     * @param string $json
     * @return array The normalised list.
     * @throws moodle_exception error:invalidoperations or error:foreignid
     */
    public static function parse(int $courseid, string $json): array {
        global $DB;
        if (strlen($json) > self::MAX_BYTES) {
            throw self::invalid('too big');
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw self::invalid('not a JSON object');
        }
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::TOP, true)) {
                throw self::invalid('unknown key ' . self::short($key));
            }
        }
        $cms = $DB->get_records('course_modules', ['course' => $courseid], '', 'id');
        $sections = $DB->get_records('course_sections', ['course' => $courseid], '', 'id');
        $gradeitems = $DB->get_records('grade_items', ['courseid' => $courseid], '', 'id');
        $groups = $DB->get_records('groups', ['courseid' => $courseid], '', 'id');
        $groupings = $DB->get_records('groupings', ['courseid' => $courseid], '', 'id');
        $ctx = (object) ['cms' => $cms, 'sections' => $sections, 'gradeitems' => $gradeitems, 'groups' => $groups,
            'groupings' => $groupings];
        $out = [];
        foreach (self::TOP as $key) {
            $list = $data[$key] ?? [];
            if ($key === 'coursesettings') {
                $out[$key] = self::coursesettings($list, $courseid, $ctx);
                continue;
            }
            if (!is_array($list)) {
                throw self::invalid($key . ' is not a list');
            }
            $out[$key] = $list;
        }
        $newsectionkeys = [];
        foreach ($out['newsections'] as $i => $n) {
            $n = self::object($n, ['key', 'number', 'name'], 'newsection');
            $key = self::key($n['key'] ?? null);
            if (isset($newsectionkeys[$key])) {
                throw self::invalid('repeated key');
            }
            $newsectionkeys[$key] = true;
            $out['newsections'][$i] = [
                'key' => $key,
                'number' => self::int($n['number'] ?? null, 1),
                'name' => self::text($n['name'] ?? '', 255),
            ];
        }
        $addedkeys = [];
        foreach ($out['added'] as $i => $a) {
            $a = self::object($a, ['key', 'module', 'name', 'section'], 'added');
            $key = self::key($a['key'] ?? null);
            if (isset($addedkeys[$key]) || isset($newsectionkeys[$key])) {
                throw self::invalid('repeated key');
            }
            $addedkeys[$key] = true;
            $module = (string) ($a['module'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_]{0,30}$/', $module)) {
                throw self::invalid('module');
            }
            if (trim((string) ($a['name'] ?? '')) === '') {
                throw self::invalid('empty name');
            }
            $out['added'][$i] = ['key' => $key, 'module' => $module, 'name' => self::text($a['name'] ?? '', 1333),
                'section' => self::section_ref($a['section'] ?? null, $sections, $newsectionkeys)];
        }
        foreach ($out['activities'] as $i => $a) {
            $out['activities'][$i] = self::activity($a, $ctx);
        }
        $seen = [];
        foreach ($out['activities'] as $a) {
            if (isset($seen[$a['cmid']])) {
                throw self::invalid('activity repeated');
            }
            $seen[$a['cmid']] = true;
        }
        foreach ($out['retired'] as $i => $r) {
            $r = self::object($r, ['cmid'], 'retired');
            $out['retired'][$i] = ['cmid' => self::cm_ref($r['cmid'] ?? null, $cms)];
        }
        foreach ($out['sections'] as $i => $s) {
            $s = self::object($s, ['id', 'name', 'summary'], 'section');
            $row = ['id' => self::section_id($s['id'] ?? null, $sections)];
            if (array_key_exists('name', $s)) {
                $row['name'] = self::text($s['name'], 255);
            }
            if (array_key_exists('summary', $s)) {
                $row['summary'] = self::html($s['summary']);
            }
            $out['sections'][$i] = $row;
        }
        foreach ($out['retiredsections'] as $i => $s) {
            $s = self::object($s, ['id'], 'retiredsection');
            $out['retiredsections'][$i] = ['id' => self::section_id($s['id'] ?? null, $sections)];
        }
        foreach ($out['order'] as $i => $o) {
            $o = self::object($o, ['section', 'items'], 'order');
            $items = $o['items'] ?? null;
            if (!is_array($items)) {
                throw self::invalid('order items');
            }
            $list = [];
            foreach ($items as $item) {
                if (is_int($item)) {
                    $list[] = self::cm_ref($item, $cms);
                } else if (is_string($item) && isset($addedkeys[$item])) {
                    $list[] = $item;
                } else {
                    throw self::invalid('order item');
                }
            }
            $out['order'][$i] = [
                'section' => self::section_ref($o['section'] ?? null, $sections, $newsectionkeys),
                'items' => $list,
            ];
        }
        foreach ($out['sectionorder'] as $i => $s) {
            $out['sectionorder'][$i] = self::section_ref($s, $sections, $newsectionkeys);
        }
        return $out;
    }

    /**
     * One activity operation.
     *
     * @param mixed $a
     * @param \stdClass $ctx The ids of the course.
     * @return array
     */
    protected static function activity($a, \stdClass $ctx): array {
        $a = self::object($a, self::ACTIVITY, 'activity');
        $row = ['cmid' => self::cm_ref($a['cmid'] ?? null, $ctx->cms)];
        if (array_key_exists('name', $a)) {
            $row['name'] = self::text($a['name'], 1333);
            if (trim($row['name']) === '') {
                throw self::invalid('empty name');
            }
        }
        if (array_key_exists('text', $a)) {
            $t = self::object($a['text'], ['field', 'html'], 'text');
            $field = $t['field'] ?? '';
            if (!in_array($field, ['intro', 'content'], true)) {
                throw self::invalid('text field');
            }
            $row['text'] = ['field' => $field, 'html' => self::html($t['html'] ?? null)];
        }
        if (array_key_exists('completion', $a)) {
            $c = self::object($a['completion'], array_merge(self::COMPLETION, ['type']), 'completion');
            $comp = [];
            foreach (self::COMPLETION as $col) {
                if (!array_key_exists($col, $c)) {
                    // The expected date is the course team's own choice in Moodle: when the list does not say it, it stays.
                    if ($col === 'completionexpected') {
                        continue;
                    }
                    throw self::invalid('completion needs ' . $col);
                }
                $v = $c[$col];
                $comp[$col] = ($v === null && $col === 'completiongradeitemnumber') ? null : self::int($v, 0);
            }
            $type = $c['type'] ?? [];
            if (!is_array($type)) {
                throw self::invalid('completion type');
            }
            $typecols = [];
            foreach ($type as $col => $v) {
                if (!is_string($col) || !preg_match('/^completion[a-z]{2,40}$/', $col) || in_array($col, self::COMPLETION, true)) {
                    throw self::invalid('completion column');
                }
                $typecols[$col] = self::int($v, 0);
            }
            $comp['type'] = $typecols;
            $row['completion'] = $comp;
        }
        if (array_key_exists('availability', $a)) {
            $av = $a['availability'];
            if ($av !== null) {
                if (!is_string($av)) {
                    throw self::invalid('availability');
                }
                self::check_availability($av, $ctx);
            }
            $row['availability'] = $av;
        }
        if (array_key_exists('visible', $a)) {
            if (!is_bool($a['visible'])) {
                throw self::invalid('visible');
            }
            $row['visible'] = $a['visible'];
        }
        if (array_key_exists('dates', $a)) {
            if (!is_array($a['dates'])) {
                throw self::invalid('dates');
            }
            $dates = [];
            foreach ($a['dates'] as $col => $v) {
                $dates[self::text((string) $col, 40)] = self::int($v, 0);
            }
            $row['dates'] = $dates;
        }
        if (array_key_exists('grademax', $a)) {
            if (!is_int($a['grademax']) && !is_float($a['grademax'])) {
                throw self::invalid('grademax');
            }
            if ($a['grademax'] <= 0 || $a['grademax'] > 100000) {
                throw self::invalid('grademax range');
            }
            $row['grademax'] = (float) $a['grademax'];
        }
        return $row;
    }

    /**
     * Walks an availability tree: the activities, grade items, groups and groupings it names must be of THIS course.
     *
     * @param string $json
     * @param \stdClass $ctx
     * @throws moodle_exception
     */
    public static function check_availability(string $json, \stdClass $ctx): void {
        $tree = json_decode($json, true);
        if (!is_array($tree) || !isset($tree['op'], $tree['c']) || !is_array($tree['c'])) {
            throw self::invalid('availability tree');
        }
        $walk = function (array $node, int $depth) use (&$walk, $ctx): void {
            if ($depth > 10) {
                throw self::invalid('availability depth');
            }
            if (isset($node['c'])) {
                if (!is_array($node['c'])) {
                    throw self::invalid('availability children');
                }
                foreach ($node['c'] as $child) {
                    if (!is_array($child)) {
                        throw self::invalid('availability child');
                    }
                    $walk($child, $depth + 1);
                }
                return;
            }
            $type = $node['type'] ?? '';
            if ($type === 'completion') {
                // The value -1 is Moodle's own "previous activity with completion"; anything else must be an activity of this
                // course.
                $cm = $node['cm'] ?? null;
                if (!is_int($cm) || ($cm !== -1 && !isset($ctx->cms[$cm]))) {
                    throw new moodle_exception('error:foreignid', 'local_miwlab');
                }
            } else if ($type === 'grade') {
                $id = $node['id'] ?? null;
                if (!is_int($id) || !isset($ctx->gradeitems[$id])) {
                    throw new moodle_exception('error:foreignid', 'local_miwlab');
                }
            } else if ($type === 'group') {
                $id = $node['id'] ?? 0;
                if (!is_int($id) || ($id !== 0 && !isset($ctx->groups[$id]))) {
                    throw new moodle_exception('error:foreignid', 'local_miwlab');
                }
            } else if ($type === 'grouping') {
                $id = $node['id'] ?? null;
                if (!is_int($id) || !isset($ctx->groupings[$id])) {
                    throw new moodle_exception('error:foreignid', 'local_miwlab');
                }
            }
        };
        $walk($tree, 0);
    }

    /**
     * The course-wide settings, each with the choice of who sends it (keep is the default).
     *
     * @param mixed $c
     * @param int $courseid
     * @param \stdClass $ctx
     * @return array
     */
    protected static function coursesettings($c, int $courseid, \stdClass $ctx): array {
        global $DB;
        if ($c === [] || $c === null) {
            return [];
        }
        $c = self::object($c, ['completion', 'gradebook', 'blocks'], 'coursesettings');
        $out = [];
        $choice = function ($v): string {
            if (!in_array($v, ['keep', 'replace'], true)) {
                throw self::invalid('choice');
            }
            return $v;
        };
        if (array_key_exists('completion', $c)) {
            $x = self::object($c['completion'], ['choice', 'aggregation', 'criteria'], 'completion setting');
            $criteria = [];
            foreach (($x['criteria'] ?? []) as $k) {
                $k = self::object($k, ['type', 'courseinstance'], 'criterion');
                $id = $k['courseinstance'] ?? null;
                if (
                    ($k['type'] ?? null) !== 7 || !is_int($id) || $id === $courseid ||
                    !$DB->record_exists('course', ['id' => $id])
                ) {
                    throw self::invalid('criterion');
                }
                $criteria[] = ['type' => 7, 'courseinstance' => $id];
            }
            $agg = $x['aggregation'] ?? 1;
            if (!in_array($agg, [1, 2], true)) {
                throw self::invalid('aggregation');
            }
            $out['completion'] = ['choice' => $choice($x['choice'] ?? 'keep'), 'aggregation' => $agg, 'criteria' => $criteria];
        }
        if (array_key_exists('gradebook', $c)) {
            $x = self::object($c['gradebook'], ['choice', 'category'], 'gradebook setting');
            $cat = self::object($x['category'] ?? [], self::CATEGORY_SETTINGS, 'category');
            $clean = [];
            foreach ($cat as $k => $v) {
                $clean[$k] = self::int($v, 0);
            }
            $out['gradebook'] = ['choice' => $choice($x['choice'] ?? 'keep'), 'category' => $clean];
        }
        if (array_key_exists('blocks', $c)) {
            if (!is_array($c['blocks'])) {
                throw self::invalid('blocks');
            }
            $out['blocks'] = [];
            foreach ($c['blocks'] as $b) {
                $b = self::object($b, ['proposal', 'choice', 'name', 'instanceid', 'config'], 'block');
                if (
                    !in_array($b['proposal'] ?? '', ['add', 'replace', 'remove'], true) ||
                    !in_array($b['choice'] ?? 'keep', ['keep', 'apply'], true) ||
                        !is_string($b['name'] ?? null) ||
                        !preg_match('/^[a-z][a-z0-9_]{0,40}$/', $b['name'])
                ) {
                    throw self::invalid('block');
                }
                $row = ['proposal' => $b['proposal'], 'choice' => $b['choice'] ?? 'keep', 'name' => $b['name']];
                if ($b['proposal'] !== 'add') {
                    $id = $b['instanceid'] ?? null;
                    $ok = is_int($id) && $DB->record_exists('block_instances', ['id' => $id, 'blockname' => $b['name'],
                        'parentcontextid' => \context_course::instance($courseid)->id]);
                    if (!$ok) {
                        throw new moodle_exception('error:foreignid', 'local_miwlab');
                    }
                    $row['instanceid'] = $id;
                }
                if (array_key_exists('config', $b)) {
                    if (!is_array($b['config']) || strlen(json_encode($b['config'])) > 65536) {
                        throw self::invalid('block config');
                    }
                    $row['config'] = $b['config'];
                }
                $out['blocks'][] = $row;
            }
        }
        return $out;
    }

    /**
     * The exception of a list that is refused.
     *
     * @param string $detail
     * @return moodle_exception
     */
    protected static function invalid(string $detail): moodle_exception {
        return new moodle_exception('error:invalidoperations', 'local_miwlab', '', $detail);
    }

    /**
     * A key of the list, cut and cleaned, safe to put in a message.
     *
     * @param mixed $key
     * @return string
     */
    protected static function short($key): string {
        return substr(preg_replace('/[^A-Za-z0-9_]/', '?', (string) $key), 0, 30);
    }

    /**
     * Checks that a value is an object (JSON) and that it has no key outside the list allowed.
     *
     * @param mixed $v
     * @param string[] $allowed
     * @param string $what
     * @return array
     */
    protected static function object($v, array $allowed, string $what): array {
        if (!is_array($v)) {
            throw self::invalid($what . ' is not an object');
        }
        foreach (array_keys($v) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw self::invalid($what . ' has an unknown key ' . self::short($k));
            }
        }
        return $v;
    }

    /**
     * Reads a key the MiwLab gave to a new activity or section.
     *
     * @param mixed $v
     * @return string
     */
    protected static function key($v): string {
        if (!is_string($v) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', $v)) {
            throw self::invalid('key');
        }
        return $v;
    }

    /**
     * Reads an integer not below a minimum.
     *
     * @param mixed $v
     * @param int $min
     * @return int
     */
    protected static function int($v, int $min): int {
        if (!is_int($v) || $v < $min || $v > 4294967295) {
            throw self::invalid('integer');
        }
        return $v;
    }

    /**
     * Reads a text with a maximum length, without control characters.
     *
     * @param mixed $v
     * @param int $max
     * @return string
     */
    protected static function text($v, int $max): string {
        if (!is_string($v) || strlen($v) > $max * 4 || strpos($v, "\0") !== false) {
            throw self::invalid('text');
        }
        return $v;
    }

    /**
     * Reads an HTML text, with the size limit of a text of an activity.
     *
     * @param mixed $v
     * @return string
     */
    protected static function html($v): string {
        if (!is_string($v) || strlen($v) > 1048576 || strpos($v, "\0") !== false) {
            throw self::invalid('html');
        }
        return $v;
    }

    /**
     * Reads the id of an activity and checks it is one of THIS course.
     *
     * @param mixed $v
     * @param array $cms
     * @return int
     */
    protected static function cm_ref($v, array $cms): int {
        if (!is_int($v)) {
            throw self::invalid('activity id');
        }
        if (!isset($cms[$v])) {
            throw new moodle_exception('error:foreignid', 'local_miwlab');
        }
        return $v;
    }

    /**
     * Reads the id of a section and checks it is one of THIS course.
     *
     * @param mixed $v
     * @param array $sections
     * @return int
     */
    protected static function section_id($v, array $sections): int {
        if (!is_int($v)) {
            throw self::invalid('section id');
        }
        if (!isset($sections[$v])) {
            throw new moodle_exception('error:foreignid', 'local_miwlab');
        }
        return $v;
    }

    /**
     * A section of this course (its id) or the key of a new one.
     *
     * @param mixed $v
     * @param array $sections
     * @param array $newkeys
     * @return int|string
     */
    protected static function section_ref($v, array $sections, array $newkeys) {
        if (is_string($v) && isset($newkeys[$v])) {
            return $v;
        }
        return self::section_id($v, $sections);
    }
}
