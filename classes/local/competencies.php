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

use core_competency\api;
use core_competency\competency;
use core_competency\course_module_competency;
use stdClass;

/**
 * The competencies of Moodle (core_competency) for the MiwLab (1.10.0, issue 252): what exists, completing a framework, and
 * linking competencies to a course and to its activities.
 *
 * It uses the competency API of Moodle for everything it writes (so events, rules and checks are Moodle's own), and it only
 * ADDS: a framework or a competency that exists is never deleted, moved, re-scaled or hidden, and a link to a course or to an
 * activity that exists is never removed or changed. Frameworks are shared by many courses, which is why. The text of a
 * framework or of a competency (name and description) is the only thing it updates.
 *
 * The MiwLab maps a competency of its own to a "mother" competency and each skill to a "daughter"; completing the activity marks
 * the daughter as achieved, and the mother is achieved when all the daughters are (the rule "all children").
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class competencies {
    /** Most competencies in one call. */
    const MAX_COMPETENCIES = 2000;

    /** Most links in one call. */
    const MAX_LINKS = 500;

    /** Most activities in one link. */
    const MAX_ACTIVITIES = 1000;

    /** Capabilities of Moodle that allow seeing a framework. */
    const VIEW_CAPABILITIES = ['moodle/competency:competencyview', 'moodle/competency:competencymanage'];

    /** @var string[] What happens in the course or in the activity when it is completed, by name. */
    const OUTCOMES = ['none', 'evidence', 'recommend', 'complete'];

    /**
     * Fails when the competencies of Moodle are turned off in the site.
     *
     * @throws \moodle_exception error:competenciesdisabled
     */
    public static function require_enabled(): void {
        if (!api::is_enabled()) {
            throw new \moodle_exception('error:competenciesdisabled', 'local_miwlab');
        }
    }

    /**
     * The name of an outcome of a link (the same four values in the course and in the activity).
     *
     * @param int $value
     * @return string
     */
    public static function outcome_name(int $value): string {
        return [
            course_module_competency::OUTCOME_NONE => 'none',
            course_module_competency::OUTCOME_EVIDENCE => 'evidence',
            course_module_competency::OUTCOME_RECOMMEND => 'recommend',
            course_module_competency::OUTCOME_COMPLETE => 'complete',
        ][$value] ?? 'none';
    }

    /**
     * The value of an outcome of a link, from its name.
     *
     * @param string $name
     * @return int
     */
    public static function outcome_value(string $name): int {
        $values = [
            'none' => course_module_competency::OUTCOME_NONE,
            'evidence' => course_module_competency::OUTCOME_EVIDENCE,
            'recommend' => course_module_competency::OUTCOME_RECOMMEND,
            'complete' => course_module_competency::OUTCOME_COMPLETE,
        ];
        if (!isset($values[$name])) {
            throw new \moodle_exception('error:invalidcompetencies', 'local_miwlab', '', 'outcome');
        }
        return $values[$name];
    }

    /**
     * The frameworks the account can see, with their scale and how many competencies each has.
     *
     * @return array[]
     */
    public static function frameworks(): array {
        global $DB;
        self::require_enabled();
        $counts = $DB->get_records_sql('SELECT competencyframeworkid AS id, COUNT(1) AS total FROM {competency} '
            . 'GROUP BY competencyframeworkid');
        $out = [];
        foreach ($DB->get_records('competency_framework', null, 'shortname ASC, id ASC') as $framework) {
            $context = \context::instance_by_id((int) $framework->contextid, IGNORE_MISSING);
            if (!$context || !has_any_capability(self::VIEW_CAPABILITIES, $context)) {
                continue;
            }
            $scale = $DB->get_record('scale', ['id' => $framework->scaleid]);
            $out[] = [
                'id' => (int) $framework->id,
                'idnumber' => (string) $framework->idnumber,
                'shortname' => (string) $framework->shortname,
                'description' => (string) $framework->description,
                'visible' => (bool) $framework->visible,
                'categoryid' => $context->contextlevel == CONTEXT_COURSECAT ? (int) $context->instanceid : 0,
                'scaleid' => (int) $framework->scaleid,
                'scalename' => $scale ? (string) $scale->name : '',
                'scaleitems' => $scale ? self::scale_items((string) $scale->scale) : [],
                'scaleconfiguration' => (string) $framework->scaleconfiguration,
                'competencycount' => (int) ($counts[$framework->id]->total ?? 0),
            ];
        }
        return $out;
    }

    /**
     * The items of a scale, from the lowest.
     *
     * @param string $scale
     * @return string[]
     */
    public static function scale_items(string $scale): array {
        return array_values(array_filter(array_map('trim', explode(',', $scale)), 'strlen'));
    }

    /**
     * The competencies of a framework (flat; the parent says where each one stands), with the rule of each.
     *
     * @param int $frameworkid
     * @return array[]
     */
    public static function competencies_of(int $frameworkid): array {
        global $DB;
        self::require_enabled();
        $framework = $DB->get_record('competency_framework', ['id' => $frameworkid]);
        if (!$framework) {
            throw new \moodle_exception('error:frameworknotfound', 'local_miwlab');
        }
        $context = \context::instance_by_id((int) $framework->contextid);
        if (!has_any_capability(self::VIEW_CAPABILITIES, $context)) {
            throw new \required_capability_exception($context, self::VIEW_CAPABILITIES[0], 'nopermissions', '');
        }
        $rows = $DB->get_records('competency', ['competencyframeworkid' => $frameworkid], 'path ASC, sortorder ASC, id ASC');
        $out = [];
        foreach ($rows as $row) {
            $parent = $row->parentid ? ($rows[$row->parentid]->idnumber ?? '') : '';
            $out[] = [
                'id' => (int) $row->id,
                'idnumber' => (string) $row->idnumber,
                'shortname' => (string) $row->shortname,
                'description' => (string) $row->description,
                'parentid' => (int) $row->parentid,
                'parentidnumber' => (string) $parent,
                'sortorder' => (int) $row->sortorder,
                'ruletype' => (string) $row->ruletype,
                'ruleoutcome' => $row->ruletype ? self::rule_outcome_name((int) $row->ruleoutcome) : 'none',
                'scaleid' => (int) $row->scaleid,
            ];
        }
        return $out;
    }

    /**
     * The name of the outcome of the rule of a competency (not the same numbers as the link to an activity).
     *
     * @param int $value
     * @return string
     */
    protected static function rule_outcome_name(int $value): string {
        return [
            competency::OUTCOME_NONE => 'none',
            competency::OUTCOME_EVIDENCE => 'evidence',
            competency::OUTCOME_COMPLETE => 'complete',
            competency::OUTCOME_RECOMMEND => 'recommend',
        ][$value] ?? 'none';
    }

    /**
     * The scale of a new framework, and the configuration the framework needs: the highest item is the default (what completing an
     * activity gives) and the one that counts as achieved. With no scale given, the default one of Moodle ("Not yet competent,
     * Competent").
     *
     * @param int $scaleid 0: the default scale of Moodle.
     * @return array [scaleid, configuration (JSON)]
     */
    public static function scale_for_framework(int $scaleid): array {
        global $DB;
        if ($scaleid > 0) {
            $scale = $DB->get_record('scale', ['id' => $scaleid]);
        } else {
            $scale = false;
            $wanted = get_string('defaultcompetencescalenotproficient') . ',' . get_string('defaultcompetencescaleproficient');
            foreach (array_unique([$wanted, 'Not yet competent,Competent']) as $items) {
                $scale = $DB->get_record_select(
                    'scale',
                    'courseid = 0 AND ' . $DB->sql_compare_text('scale', 255) . ' = ' . $DB->sql_compare_text(':scale', 255),
                    ['scale' => $items],
                    '*',
                    IGNORE_MULTIPLE
                );
                if ($scale) {
                    break;
                }
            }
        }
        if (!$scale || (int) $scale->courseid !== 0) {
            // A scale of a course cannot be a framework's: a framework belongs to the site or to a category.
            throw new \moodle_exception('error:noscale', 'local_miwlab');
        }
        $items = self::scale_items((string) $scale->scale);
        if (count($items) < 2) {
            throw new \moodle_exception('error:noscale', 'local_miwlab');
        }
        // Moodle gives the "default" grade when an activity marks the competency as complete, so the default has to be a proficient
        // one: the highest item is both (a default on the lowest item would "complete" a competency as not yet achieved).
        $config = [['scaleid' => (string) $scale->id]];
        foreach ($items as $i => $unused) {
            $last = $i === count($items) - 1;
            // Both keys on every item: Moodle reads them without checking they exist.
            $entry = ['id' => $i + 1, 'scaledefault' => $last ? 1 : 0, 'proficient' => $last ? 1 : 0];
            $config[] = $entry;
        }
        return [(int) $scale->id, json_encode($config)];
    }

    /**
     * Completes a framework: creates it when no framework has its ID number, updates its text when it has, adds the competencies
     * that are missing and updates the text of the ones that are there. Deletes, moves and changes nothing else.
     *
     * @param array $framework [idnumber, shortname, description, scaleid, categoryid]
     * @param array[] $items Each [idnumber, shortname, description, parentidnumber]; the order does not matter.
     * @param bool $parentrule Whether a competency that has daughters in the list and no rule gets the rule "all children
     *   achieved, then achieved" (Moodle takes the rule of a mother away when a daughter is added, so it is set after).
     * @return array [frameworkid, frameworkaction, competencies => [[idnumber, id, action, reason, rule]]]
     */
    public static function complete_framework(array $framework, array $items, bool $parentrule): array {
        global $DB;
        self::require_enabled();
        if (count($items) > self::MAX_COMPETENCIES) {
            throw new \moodle_exception('error:toomanyitems', 'local_miwlab', '', self::MAX_COMPETENCIES);
        }
        $idnumber = trim((string) $framework['idnumber']);
        if ($idnumber === '' || \core_text::strlen($idnumber) > 100) {
            throw new \moodle_exception('error:invalidcompetencies', 'local_miwlab', '', 'framework idnumber');
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $record = $DB->get_record('competency_framework', ['idnumber' => $idnumber]);
            $shortname = trim((string) ($framework['shortname'] ?? ''));
            $description = (string) ($framework['description'] ?? '');
            if ($record) {
                $context = \context::instance_by_id((int) $record->contextid);
                require_capability('moodle/competency:competencymanage', $context);
                $change = ['id' => (int) $record->id];
                if ($shortname !== '' && $shortname !== (string) $record->shortname) {
                    $change['shortname'] = $shortname;
                }
                if ($description !== '' && $description !== (string) $record->description) {
                    $change['description'] = $description;
                }
                $frameworkaction = 'unchanged';
                if (count($change) > 1) {
                    api::update_framework((object) $change);
                    $frameworkaction = 'updated';
                }
                $frameworkid = (int) $record->id;
            } else {
                if ($shortname === '' || \core_text::strlen($shortname) > 100) {
                    throw new \moodle_exception('error:invalidcompetencies', 'local_miwlab', '', 'framework shortname');
                }
                $categoryid = (int) ($framework['categoryid'] ?? 0);
                if ($categoryid > 0) {
                    if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
                        throw new \moodle_exception('error:categorynotfound', 'local_miwlab');
                    }
                    $context = \context_coursecat::instance($categoryid);
                } else {
                    $context = \context_system::instance();
                }
                [$scaleid, $configuration] = self::scale_for_framework((int) ($framework['scaleid'] ?? 0));
                $created = api::create_framework((object) [
                    'idnumber' => $idnumber,
                    'shortname' => $shortname,
                    'description' => $description,
                    'descriptionformat' => FORMAT_HTML,
                    'visible' => 1,
                    'scaleid' => $scaleid,
                    'scaleconfiguration' => $configuration,
                    'contextid' => $context->id,
                    'taxonomies' => 'competency,skill',
                ]);
                $frameworkid = (int) $created->get('id');
                $frameworkaction = 'created';
            }
            $results = self::complete_competencies($frameworkid, $items, $parentrule);
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return ['frameworkid' => $frameworkid, 'frameworkaction' => $frameworkaction, 'competencies' => $results];
    }

    /**
     * Adds the missing competencies of a framework and updates the text of the ones that exist.
     *
     * @param int $frameworkid
     * @param array[] $items
     * @param bool $parentrule
     * @return array[]
     */
    protected static function complete_competencies(int $frameworkid, array $items, bool $parentrule): array {
        global $DB;
        $known = [];
        foreach ($DB->get_records('competency', ['competencyframeworkid' => $frameworkid]) as $row) {
            $known[(string) $row->idnumber] = $row;
        }
        $results = [];
        $pending = [];
        $seen = [];
        foreach ($items as $item) {
            $id = trim((string) $item['idnumber']);
            $name = trim((string) ($item['shortname'] ?? ''));
            $base = ['idnumber' => $id, 'id' => 0, 'action' => 'skipped', 'reason' => '', 'rule' => ''];
            if ($id === '' || \core_text::strlen($id) > 100) {
                $results[] = ['reason' => 'invalid'] + $base;
            } else if (isset($seen[$id])) {
                $results[] = ['reason' => 'duplicated'] + $base;
            } else if ($name !== '' && \core_text::strlen($name) > 100) {
                $seen[$id] = true;
                $results[] = ['reason' => 'invalid'] + $base;
            } else if (!isset($known[$id]) && $name === '') {
                $seen[$id] = true;
                $results[] = ['reason' => 'invalid'] + $base;
            } else {
                $seen[$id] = true;
                $pending[$id] = $item + ['parentidnumber' => ''];
            }
        }
        // A competency waits until its parent is there; the loop ends when a round does nothing.
        while ($pending) {
            $progress = false;
            foreach ($pending as $id => $item) {
                $parent = trim((string) $item['parentidnumber']);
                if ($parent !== '' && !isset($known[$parent])) {
                    continue;
                }
                $progress = true;
                unset($pending[$id]);
                $results[] = self::complete_competency($frameworkid, $id, $item, $parent, $known);
            }
            if (!$progress) {
                break;
            }
        }
        foreach ($pending as $id => $item) {
            $results[] = ['idnumber' => $id, 'id' => 0, 'action' => 'skipped', 'reason' => 'parentnotfound', 'rule' => ''];
        }
        if ($parentrule) {
            $mothers = [];
            foreach ($items as $item) {
                $parent = trim((string) ($item['parentidnumber'] ?? ''));
                if ($parent !== '' && isset($known[$parent])) {
                    $mothers[$parent] = true;
                }
            }
            foreach (array_keys($mothers) as $parent) {
                $rule = self::set_mother_rule((int) $known[$parent]->id);
                $at = array_search($parent, array_column($results, 'idnumber'), true);
                if ($at !== false) {
                    $results[$at]['rule'] = $rule;
                } else {
                    // A mother that is only a parent in the list (it exists in the framework already): the rule is told with no
                    // change of text.
                    $results[] = ['idnumber' => $parent, 'id' => (int) $known[$parent]->id, 'action' => 'unchanged',
                        'reason' => '', 'rule' => $rule];
                }
            }
        }
        return $results;
    }

    /**
     * Creates one competency or updates the text of the one that exists.
     *
     * @param int $frameworkid
     * @param string $id
     * @param array $item
     * @param string $parent ID number of the parent, '' for none.
     * @param stdClass[] $known The competencies of the framework by ID number; this one is added when created.
     * @return array
     */
    protected static function complete_competency(int $frameworkid, string $id, array $item, string $parent, array &$known): array {
        $name = trim((string) ($item['shortname'] ?? ''));
        $description = (string) ($item['description'] ?? '');
        if (isset($known[$id])) {
            $row = $known[$id];
            $reason = '';
            $parentid = $parent === '' ? 0 : (int) $known[$parent]->id;
            if ((int) $row->parentid !== $parentid) {
                // A competency is never moved: the framework is shared.
                $reason = 'parentkept';
            }
            $change = ['id' => (int) $row->id];
            if ($name !== '' && $name !== (string) $row->shortname) {
                $change['shortname'] = $name;
            }
            if ($description !== '' && $description !== (string) $row->description) {
                $change['description'] = $description;
            }
            if (count($change) > 1) {
                api::update_competency((object) $change);
                foreach ($change as $field => $value) {
                    $row->$field = $value;
                }
                return ['idnumber' => $id, 'id' => (int) $row->id, 'action' => 'updated', 'reason' => $reason, 'rule' => ''];
            }
            return ['idnumber' => $id, 'id' => (int) $row->id, 'action' => 'unchanged', 'reason' => $reason, 'rule' => ''];
        }
        $created = api::create_competency((object) [
            'shortname' => $name,
            'idnumber' => $id,
            'description' => $description,
            'descriptionformat' => FORMAT_HTML,
            'parentid' => $parent === '' ? 0 : (int) $known[$parent]->id,
            'competencyframeworkid' => $frameworkid,
        ]);
        $known[$id] = (object) $created->to_record();
        return ['idnumber' => $id, 'id' => (int) $created->get('id'), 'action' => 'created', 'reason' => '', 'rule' => ''];
    }

    /**
     * Gives a mother competency the rule "all children achieved, then achieved", when it has no rule. A rule of the organisation
     * (any other, or the same with another outcome) stays.
     *
     * @param int $competencyid
     * @return string set, kept (it has the rule already, or another one) or '' (nothing to set: it has no children)
     */
    protected static function set_mother_rule(int $competencyid): string {
        global $DB;
        $row = $DB->get_record('competency', ['id' => $competencyid], '*', MUST_EXIST);
        if (!$DB->record_exists('competency', ['parentid' => $competencyid])) {
            return '';
        }
        if (!empty($row->ruletype)) {
            return 'kept';
        }
        api::update_competency((object) [
            'id' => $competencyid,
            'ruletype' => \core_competency\competency_rule_all::class,
            'ruleoutcome' => competency::OUTCOME_COMPLETE,
            'ruleconfig' => null,
        ]);
        return 'set';
    }

    /**
     * The competencies linked to a course, and the activities each is linked to.
     *
     * @param stdClass $course
     * @return array[]
     */
    public static function course_links(stdClass $course): array {
        global $DB;
        self::require_enabled();
        $rows = $DB->get_records_sql(
            'SELECT cc.competencyid, cc.ruleoutcome, c.idnumber, c.shortname, c.competencyframeworkid,
                    f.idnumber AS frameworkidnumber
               FROM {competency_coursecomp} cc
               JOIN {competency} c ON c.id = cc.competencyid
               JOIN {competency_framework} f ON f.id = c.competencyframeworkid
              WHERE cc.courseid = :course
           ORDER BY cc.sortorder, cc.id',
            ['course' => $course->id]
        );
        $modules = [];
        $sql = 'SELECT mc.id, mc.cmid, mc.competencyid, mc.ruleoutcome
                  FROM {competency_modulecomp} mc
                  JOIN {course_modules} cm ON cm.id = mc.cmid
                 WHERE cm.course = :course
              ORDER BY mc.cmid, mc.sortorder';
        foreach ($DB->get_records_sql($sql, ['course' => $course->id]) as $module) {
            $modules[(int) $module->competencyid][] = ['cmid' => (int) $module->cmid,
                'ruleoutcome' => self::outcome_name((int) $module->ruleoutcome)];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'competencyid' => (int) $row->competencyid,
                'idnumber' => (string) $row->idnumber,
                'shortname' => (string) $row->shortname,
                'frameworkid' => (int) $row->competencyframeworkid,
                'frameworkidnumber' => (string) $row->frameworkidnumber,
                'ruleoutcome' => self::outcome_name((int) $row->ruleoutcome),
                'activities' => $modules[(int) $row->competencyid] ?? [],
            ];
        }
        return $out;
    }

    /**
     * Links competencies to a course and to some of its activities, with what happens when the activity is completed. A link that
     * exists is left as it is (and told), and nothing is removed.
     *
     * @param stdClass $course
     * @param array[] $links Each [competencyid, cmids[], outcome (name)].
     * @return array[] Each [competencyid, courseaction, reason, completeachieves,
     *   activities => [[cmid, action, ruleoutcome, reason]]]
     */
    public static function link_to_course(stdClass $course, array $links): array {
        global $DB;
        self::require_enabled();
        if (count($links) > self::MAX_LINKS) {
            throw new \moodle_exception('error:toomanyitems', 'local_miwlab', '', self::MAX_LINKS);
        }
        $cms = $DB->get_records('course_modules', ['course' => $course->id], '', 'id, deletioninprogress');
        $transaction = $DB->start_delegated_transaction();
        $results = [];
        try {
            foreach ($links as $link) {
                $results[] = self::link_one($course, $cms, (int) $link['competencyid'], $link['cmids'], (string) $link['outcome']);
            }
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        $transaction->allow_commit();
        return $results;
    }

    /**
     * One competency linked to the course and to some activities.
     *
     * @param stdClass $course
     * @param stdClass[] $cms The course modules of the course.
     * @param int $competencyid
     * @param int[] $cmids
     * @param string $outcome
     * @return array
     */
    protected static function link_one(stdClass $course, array $cms, int $competencyid, array $cmids, string $outcome): array {
        global $DB;
        $value = self::outcome_value($outcome);
        if (count($cmids) > self::MAX_ACTIVITIES) {
            throw new \moodle_exception('error:toomanyitems', 'local_miwlab', '', self::MAX_ACTIVITIES);
        }
        $result = ['competencyid' => $competencyid, 'courseaction' => 'skipped', 'reason' => '', 'completeachieves' => false,
            'activities' => []];
        $competency = $DB->get_record('competency', ['id' => $competencyid]);
        $framework = $competency ? $DB->get_record('competency_framework', ['id' => $competency->competencyframeworkid]) : false;
        if (!$competency || !$framework) {
            $result['reason'] = 'competencynotfound';
            return $result;
        }
        if (!has_any_capability(self::VIEW_CAPABILITIES, \context::instance_by_id((int) $framework->contextid))) {
            $result['reason'] = 'nopermission';
            return $result;
        }
        if (!$framework->visible) {
            // Moodle refuses to link a competency of a hidden framework to a course.
            $result['reason'] = 'frameworkhidden';
            return $result;
        }
        $result['courseaction'] = api::add_competency_to_course($course->id, $competencyid) ? 'added' : 'existing';
        // What "complete" gives is the default grade of the framework; if that one is not proficient, completing the activity
        // would rate the competency without achieving it (a framework of the organisation may be set up so).
        [$unused, $proficient] = (new competency($competencyid))->get_default_grade();
        $result['completeachieves'] = (bool) $proficient;
        foreach (array_unique(array_map('intval', $cmids)) as $cmid) {
            $row = ['cmid' => $cmid, 'action' => 'skipped', 'ruleoutcome' => '', 'reason' => ''];
            if (!isset($cms[$cmid])) {
                $row['reason'] = 'notincourse';
            } else if (!empty($cms[$cmid]->deletioninprogress)) {
                $row['reason'] = 'deleting';
            } else {
                $have = course_module_competency::get_record(['cmid' => $cmid, 'competencyid' => $competencyid]);
                if ($have) {
                    // Whoever put it there chose what happens on completion; it stays.
                    $row['action'] = 'existing';
                    $row['ruleoutcome'] = self::outcome_name((int) $have->get('ruleoutcome'));
                } else {
                    api::add_competency_to_course_module($cmid, $competencyid);
                    $new = course_module_competency::get_record(['cmid' => $cmid, 'competencyid' => $competencyid]);
                    api::set_course_module_competency_ruleoutcome($new, $value);
                    $row['action'] = 'added';
                    $row['ruleoutcome'] = $outcome;
                }
            }
            $result['activities'][] = $row;
        }
        return $result;
    }
}
