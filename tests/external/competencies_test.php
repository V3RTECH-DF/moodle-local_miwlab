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

namespace local_miwlab\external;

use core_competency\api;
use core_competency\competency;
use core_competency\course_module_competency;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\competencies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\complete_competency_framework::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\set_course_competencies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_competency_frameworks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_competencies::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_course_competencies::class)]
/**
 * Tests of the competencies (issue 252): what exists, completing a framework (it only adds and updates text) and linking
 * competencies to a course and its activities. Run with the role the plugin builds for its key, so a missing capability shows.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\competencies
 * @covers     \local_miwlab\external\complete_competency_framework
 * @covers     \local_miwlab\external\set_course_competencies
 * @covers     \local_miwlab\external\get_competency_frameworks
 * @covers     \local_miwlab\external\get_competencies
 * @covers     \local_miwlab\external\get_course_competencies
 */
final class competencies_test extends \advanced_testcase {
    /**
     * The account of the MiwLab key, with the role the plugin builds (so the permissions are the real ones), not enrolled anywhere.
     *
     * @return \stdClass
     */
    protected function key_account(): \stdClass {
        $roleid = \local_miwlab\local\provisioning::ensure_role();
        $this->assertGreaterThan(0, $roleid);
        $user = $this->getDataGenerator()->create_user();
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * An account that holds the competency permissions of Moodle (and may see the course) but not the ones of the plugin: the API
     * of Moodle alone would let it, so only the check of the plugin refuses it.
     *
     * @return \stdClass
     */
    protected function moodle_only_account(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('SoMoodle', 'somoodle' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        foreach (
            ['moodle/competency:competencyview', 'moodle/competency:competencymanage',
            'moodle/competency:coursecompetencymanage', 'moodle/competency:coursecompetencyview', 'moodle/course:view'] as $cap
        ) {
            assign_capability($cap, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Makes sure the default competency scale exists, as it does in a Moodle installed from scratch.
     */
    protected function default_scale(): void {
        global $DB;
        $items = get_string('defaultcompetencescalenotproficient') . ',' . get_string('defaultcompetencescaleproficient');
        if (!$DB->record_exists_select('scale', 'courseid = 0 AND ' . $DB->sql_compare_text('scale', 255) . ' = ?', [$items])) {
            $this->getDataGenerator()->create_scale(['name' => 'Default competence scale', 'scale' => $items]);
        }
    }

    /**
     * A framework from the MiwLab: one mother with two daughters.
     *
     * @return array[] framework, competencies
     */
    protected function miwlab_framework(): array {
        return [
            ['idnumber' => 'miwlab-ref-1', 'shortname' => 'Referencial 1', 'description' => '<p>Texto</p>'],
            [
                ['idnumber' => 'c1', 'shortname' => 'Comunicar', 'description' => 'Comunica'],
                ['idnumber' => 'c1h1', 'shortname' => 'Escrever', 'parentidnumber' => 'c1'],
                ['idnumber' => 'c1h2', 'shortname' => 'Falar', 'parentidnumber' => 'c1'],
            ],
        ];
    }

    /**
     * Completes the framework as the MiwLab would, clean.
     *
     * @param array $result
     * @return array
     */
    protected function clean(array $result): array {
        return base::clean_returnvalue(complete_competency_framework::execute_returns(), $result);
    }

    public function test_creates_the_framework_with_a_mother_and_the_rule_of_all_children(): void {
        global $DB;
        $this->resetAfterTest();
        $this->default_scale();
        $this->setUser($this->key_account());
        [$framework, $items] = $this->miwlab_framework();
        // The daughters come first in the list on purpose: the order does not matter.
        $result = $this->clean(complete_competency_framework::execute($framework, array_reverse($items), true));
        $this->assertSame('created', $result['frameworkaction']);
        $this->assertSame(3, $result['created']);
        $byid = array_column($result['competencies'], null, 'idnumber');
        $this->assertSame('set', $byid['c1']['rule']);
        $row = $DB->get_record('competency_framework', ['id' => $result['frameworkid']]);
        $this->assertSame('Referencial 1', $row->shortname);
        $mother = $DB->get_record('competency', ['idnumber' => 'c1', 'competencyframeworkid' => $row->id]);
        $this->assertSame(\core_competency\competency_rule_all::class, $mother->ruletype);
        $this->assertEquals(competency::OUTCOME_COMPLETE, $mother->ruleoutcome);
        $daughter = $DB->get_record('competency', ['idnumber' => 'c1h1', 'competencyframeworkid' => $row->id]);
        $this->assertEquals($mother->id, $daughter->parentid);
        $config = json_decode($row->scaleconfiguration);
        $this->assertSame([1, 1], [$config[2]->scaledefault, $config[2]->proficient], 'the default is the proficient item');
        $this->assertSame([0, 0], [$config[1]->scaledefault, $config[1]->proficient]);
        // What the read functions return is the same.
        $frameworks = base::clean_returnvalue(get_competency_frameworks::execute_returns(), get_competency_frameworks::execute());
        $this->assertSame(3, array_column($frameworks['frameworks'], 'competencycount', 'idnumber')['miwlab-ref-1']);
        $listed = base::clean_returnvalue(get_competencies::execute_returns(), get_competencies::execute($result['frameworkid']));
        $listed = array_column($listed['competencies'], null, 'idnumber');
        $this->assertSame('c1', $listed['c1h2']['parentidnumber']);
        $this->assertSame('complete', $listed['c1']['ruleoutcome']);
        $this->assertSame('none', $listed['c1h1']['ruleoutcome']);
    }

    public function test_only_adds_and_updates_text_on_a_framework_that_exists(): void {
        global $DB;
        $this->resetAfterTest();
        $this->default_scale();
        $this->setUser($this->key_account());
        [$framework, $items] = $this->miwlab_framework();
        $first = $this->clean(complete_competency_framework::execute($framework, $items));
        $before = $DB->get_records('competency', ['competencyframeworkid' => $first['frameworkid']], 'id');
        // The second time: nothing changes (the control that the first run is not repeated), and the report says so.
        $again = $this->clean(complete_competency_framework::execute($framework, $items));
        $this->assertSame('unchanged', $again['frameworkaction']);
        $this->assertSame(0, $again['created']);
        $this->assertSame(0, $again['updated']);
        $this->assertSame($first['frameworkid'], $again['frameworkid']);
        $this->assertEquals($before, $DB->get_records('competency', ['competencyframeworkid' => $first['frameworkid']], 'id'));
        // A new daughter and a new text: added and updated, and the rest stays; the list lacks c1h2 and it is NOT deleted.
        $framework['shortname'] = 'Referencial 1 (novo nome)';
        $changed = [
            ['idnumber' => 'c1', 'shortname' => 'Comunicar bem', 'description' => 'Comunica'],
            ['idnumber' => 'c1h1', 'shortname' => 'Escrever', 'parentidnumber' => 'c1'],
            ['idnumber' => 'c1h3', 'shortname' => 'Ouvir', 'parentidnumber' => 'c1'],
        ];
        $next = $this->clean(complete_competency_framework::execute($framework, $changed));
        $this->assertSame('updated', $next['frameworkaction']);
        $this->assertSame(1, $next['created']);
        $this->assertSame(1, $next['updated']);
        $name = $DB->get_field('competency_framework', 'shortname', ['id' => $first['frameworkid']]);
        $this->assertSame('Referencial 1 (novo nome)', $name);
        $rows = array_column($DB->get_records('competency', ['competencyframeworkid' => $first['frameworkid']]), null, 'idnumber');
        $idnumbers = array_keys($rows);
        sort($idnumbers);
        $this->assertSame(['c1', 'c1h1', 'c1h2', 'c1h3'], $idnumbers, 'nothing was deleted');
        $this->assertSame('Comunicar bem', $rows['c1']->shortname);
        $this->assertEquals($rows['c1']->id, $rows['c1h3']->parentid);
        $this->assertSame(\core_competency\competency_rule_all::class, $rows['c1']->ruletype, 'the rule Moodle reset is set again');
    }

    public function test_never_moves_a_competency_nor_changes_the_scale_nor_overwrites_a_rule(): void {
        global $DB;
        $this->resetAfterTest();
        $this->default_scale();
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'A,B,C']);
        $framework = $generator->create_framework(['idnumber' => 'minha', 'shortname' => 'Da organizacao',
            'description' => 'Texto da organizacao', 'scaleid' => $scale->id]);
        $root = $generator->create_competency(['idnumber' => 'raiz', 'competencyframeworkid' => $framework->get('id')]);
        $other = $generator->create_competency(['idnumber' => 'outra', 'competencyframeworkid' => $framework->get('id')]);
        $child = $generator->create_competency(['idnumber' => 'filha', 'competencyframeworkid' => $framework->get('id'),
            'parentid' => $root->get('id')]);
        // The organisation's own rule on the mother (set after the daughter exists, as Moodle resets it on adding).
        $DB->set_field('competency', 'ruletype', \core_competency\competency_rule_points::class, ['id' => $root->get('id')]);
        $DB->set_field(
            'competency',
            'ruleconfig',
            json_encode(['base' => ['points' => 1], 'competencies' => []]),
            ['id' => $root->get('id')]
        );
        $this->setUser($this->key_account());
        $result = $this->clean(complete_competency_framework::execute(
            ['idnumber' => 'minha', 'scaleid' => $this->getDataGenerator()->create_scale(['scale' => 'X,Y'])->id],
            [['idnumber' => 'filha', 'shortname' => 'Filha', 'parentidnumber' => 'outra']]
        ));
        $byid = array_column($result['competencies'], null, 'idnumber');
        $this->assertSame('parentkept', $byid['filha']['reason']);
        $this->assertEquals($root->get('id'), $DB->get_field('competency', 'parentid', ['id' => $child->get('id')]), 'not moved');
        $row = $DB->get_record('competency_framework', ['id' => $framework->get('id')]);
        $this->assertEquals($scale->id, $row->scaleid, 'not re-scaled');
        $this->assertSame('Da organizacao', $DB->get_field('competency_framework', 'shortname', ['id' => $framework->get('id')]));
        $this->assertSame('Texto da organizacao', $row->description, 'an empty text keeps the text');
        $this->assertSame(
            \core_competency\competency_rule_points::class,
            $DB->get_field('competency', 'ruletype', ['id' => $root->get('id')])
        );
        // A mother that has another rule is told, and the rule stays.
        $rules = $this->clean(complete_competency_framework::execute(
            ['idnumber' => 'minha'],
            [['idnumber' => 'raiz', 'parentidnumber' => ''], ['idnumber' => 'filha', 'parentidnumber' => 'raiz']]
        ));
        $this->assertSame('kept', array_column($rules['competencies'], 'rule', 'idnumber')['raiz']);
        $this->assertSame(
            \core_competency\competency_rule_points::class,
            $DB->get_field('competency', 'ruletype', ['id' => $root->get('id')])
        );
    }

    public function test_skips_what_cannot_be_made_and_says_why(): void {
        $this->resetAfterTest();
        $this->default_scale();
        $this->setUser($this->key_account());
        $result = $this->clean(complete_competency_framework::execute(
            ['idnumber' => 'ref', 'shortname' => 'Ref'],
            [
                ['idnumber' => 'a', 'shortname' => 'A'],
                ['idnumber' => 'a', 'shortname' => 'A de novo'],
                ['idnumber' => 'orfa', 'shortname' => 'Orfa', 'parentidnumber' => 'nao-existe'],
                ['idnumber' => 'semnome'],
                ['idnumber' => '', 'shortname' => 'Sem codigo'],
            ]
        ));
        $reasons = [];
        foreach ($result['competencies'] as $row) {
            $reasons[] = $row['idnumber'] . ':' . $row['action'] . ':' . $row['reason'];
        }
        sort($reasons);
        $this->assertSame([':skipped:invalid', 'a:created:', 'a:skipped:duplicated', 'orfa:skipped:parentnotfound',
            'semnome:skipped:invalid'], $reasons);
        $this->assertSame(1, $result['created']);
        try {
            complete_competency_framework::execute(['idnumber' => 'nova'], []);
            $this->fail('created a framework without a name');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidcompetencies', $e->errorcode);
        }
    }

    public function test_an_account_without_the_permission_changes_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->default_scale();
        $this->setUser($this->getDataGenerator()->create_user());
        [$framework, $items] = $this->miwlab_framework();
        try {
            complete_competency_framework::execute($framework, $items);
            $this->fail('completed without the permission');
        } catch (\required_capability_exception $e) {
            $this->assertSame(0, $DB->count_records('competency_framework', ['idnumber' => 'miwlab-ref-1']));
        }
        // The control for the second layer: an account that holds the competency permissions of Moodle but not the one of the
        // plugin is refused by the plugin too (the API of Moodle alone would let it).
        $this->setUser($this->moodle_only_account());
        try {
            complete_competency_framework::execute($framework, $items);
            $this->fail('completed with the permissions of Moodle but not the plugin\'s');
        } catch (\required_capability_exception $e) {
            $this->assertSame(0, $DB->count_records('competency_framework', ['idnumber' => 'miwlab-ref-1']));
        }
        $this->expectException(\required_capability_exception::class);
        get_competency_frameworks::execute();
    }

    public function test_competencies_turned_off_are_told(): void {
        $this->resetAfterTest();
        $this->setUser($this->key_account());
        set_config('enabled', 0, 'core_competency');
        try {
            get_competency_frameworks::execute();
            $this->fail('read with the competencies turned off');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:competenciesdisabled', $e->errorcode);
        }
    }

    /**
     * A course with two pages that have completion, and a framework with a mother and two daughters.
     *
     * @return \stdClass
     */
    protected function linked_setup(): \stdClass {
        global $CFG;
        $this->default_scale();
        set_config('enablecompletion', 1);
        $generator = $this->getDataGenerator();
        $s = new \stdClass();
        $s->course = $generator->create_course(['enablecompletion' => 1]);
        $s->pages = [];
        foreach ([1, 2] as $i) {
            $s->pages[$i] = $generator->create_module('page', ['course' => $s->course->id, 'name' => 'P' . $i, 'completion' => 1]);
        }
        $this->setUser($this->key_account());
        [$framework, $items] = $this->miwlab_framework();
        $s->framework = $this->clean(complete_competency_framework::execute($framework, $items));
        $s->ids = array_column($s->framework['competencies'], 'id', 'idnumber');
        return $s;
    }

    public function test_links_competencies_to_the_course_and_activities_with_the_complete_rule(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->linked_setup();
        $result = base::clean_returnvalue(set_course_competencies::execute_returns(), set_course_competencies::execute(
            (int) $s->course->id,
            [
                ['competencyid' => $s->ids['c1h1'], 'cmids' => [(int) $s->pages[1]->cmid]],
                ['competencyid' => $s->ids['c1h2'], 'cmids' => [(int) $s->pages[2]->cmid, (int) $s->pages[1]->cmid]],
            ]
        ));
        $this->assertSame('added', $result['links'][0]['courseaction']);
        $this->assertTrue($result['links'][0]['completeachieves']);
        $this->assertSame('complete', $result['links'][0]['activities'][0]['ruleoutcome']);
        $this->assertSame(2, $DB->count_records('competency_coursecomp', ['courseid' => $s->course->id]));
        $this->assertSame(3, $DB->count_records('competency_modulecomp'));
        foreach ($DB->get_records('competency_modulecomp') as $link) {
            $this->assertEquals(course_module_competency::OUTCOME_COMPLETE, $link->ruleoutcome);
        }
        $read = get_course_competencies::execute((int) $s->course->id);
        $read = base::clean_returnvalue(get_course_competencies::execute_returns(), $read);
        $this->assertSame(['c1h1', 'c1h2'], array_column($read['competencies'], 'idnumber'));
        $first = [['cmid' => (int) $s->pages[1]->cmid, 'ruleoutcome' => 'complete']];
        $this->assertSame($first, $read['competencies'][0]['activities']);
        $this->assertCount(2, $read['competencies'][1]['activities']);
    }

    public function test_completing_the_activity_marks_the_skill_and_the_mother_achieved(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $s = $this->linked_setup();
        set_course_competencies::execute((int) $s->course->id, [
            ['competencyid' => $s->ids['c1h1'], 'cmids' => [(int) $s->pages[1]->cmid]],
            ['competencyid' => $s->ids['c1h2'], 'cmids' => [(int) $s->pages[2]->cmid]],
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($s->course, 'student');
        $info = new \completion_info(get_course($s->course->id));
        $this->setUser($student);
        $proficient = function (string $idnumber) use ($DB, $student, $s): bool {
            return (bool) $DB->get_field(
                'competency_usercompcourse',
                'proficiency',
                ['userid' => $student->id, 'courseid' => $s->course->id, 'competencyid' => $s->ids[$idnumber]]
            );
        };
        $info->update_state(get_fast_modinfo($s->course->id)->get_cm($s->pages[1]->cmid), COMPLETION_COMPLETE, $student->id);
        $this->assertTrue($proficient('c1h1'), 'completing the activity marks the skill as achieved');
        $this->assertFalse($proficient('c1h2'), 'the control: the other skill is not');
        $this->assertFalse((bool) $DB->get_field(
            'competency_usercomp',
            'proficiency',
            ['userid' => $student->id, 'competencyid' => $s->ids['c1']]
        ), 'the mother needs all the skills');
        $info->update_state(get_fast_modinfo($s->course->id)->get_cm($s->pages[2]->cmid), COMPLETION_COMPLETE, $student->id);
        $this->assertTrue($proficient('c1h2'));
        $this->assertTrue((bool) $DB->get_field(
            'competency_usercomp',
            'proficiency',
            ['userid' => $student->id, 'competencyid' => $s->ids['c1']]
        ), 'all the skills achieved: the mother is achieved');
    }

    public function test_a_link_that_exists_is_left_as_it_is_and_nothing_is_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->linked_setup();
        // The organisation linked the first page to a skill with "evidence" only, and the second page to another skill.
        api::add_competency_to_course($s->course->id, $s->ids['c1h1']);
        api::add_competency_to_course_module($s->pages[1]->cmid, $s->ids['c1h1']);
        api::add_competency_to_course($s->course->id, $s->ids['c1h2']);
        api::add_competency_to_course_module($s->pages[2]->cmid, $s->ids['c1h2']);
        $result = set_course_competencies::execute((int) $s->course->id, [
            ['competencyid' => $s->ids['c1h1'], 'cmids' => [(int) $s->pages[1]->cmid, (int) $s->pages[2]->cmid]],
        ]);
        $this->assertSame('existing', $result['links'][0]['courseaction']);
        $activities = array_column($result['links'][0]['activities'], null, 'cmid');
        $this->assertSame('existing', $activities[$s->pages[1]->cmid]['action']);
        $this->assertSame('evidence', $activities[$s->pages[1]->cmid]['ruleoutcome'], 'not changed to complete');
        $this->assertSame('added', $activities[$s->pages[2]->cmid]['action'], 'the control: the missing link is made');
        $this->assertSame('complete', $activities[$s->pages[2]->cmid]['ruleoutcome']);
        $this->assertSame(2, $DB->count_records('competency_coursecomp', ['courseid' => $s->course->id]));
        $this->assertSame(3, $DB->count_records('competency_modulecomp'), 'the link of the other skill stays');
    }

    public function test_links_that_cannot_be_made_are_skipped_with_the_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->linked_setup();
        $otherpage = $this->getDataGenerator()->create_module('page', ['course' => $this->getDataGenerator()->create_course()->id]);
        $hidden = $this->getDataGenerator()->get_plugin_generator('core_competency')->create_framework(['visible' => 0]);
        $hiddencomp = $this->getDataGenerator()->get_plugin_generator('core_competency')
            ->create_competency(['competencyframeworkid' => $hidden->get('id')]);
        $result = set_course_competencies::execute((int) $s->course->id, [
            ['competencyid' => 999999, 'cmids' => []],
            ['competencyid' => (int) $hiddencomp->get('id'), 'cmids' => []],
            ['competencyid' => $s->ids['c1h1'], 'cmids' => [(int) $otherpage->cmid, (int) $s->pages[1]->cmid]],
        ]);
        $this->assertSame(['competencynotfound', 'frameworkhidden', ''], array_column($result['links'], 'reason'));
        $activities = array_column($result['links'][2]['activities'], null, 'cmid');
        $this->assertSame('notincourse', $activities[$otherpage->cmid]['reason']);
        $this->assertSame(0, $DB->count_records('competency_modulecomp', ['cmid' => $otherpage->cmid]));
        $this->assertSame('added', $activities[$s->pages[1]->cmid]['action']);
        try {
            $bad = [['competencyid' => $s->ids['c1h1'], 'cmids' => [], 'outcome' => 'apagar']];
            set_course_competencies::execute((int) $s->course->id, $bad);
            $this->fail('accepted an outcome that is not one');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidcompetencies', $e->errorcode);
        }
    }

    public function test_says_when_completing_would_not_achieve_the_competency(): void {
        $this->resetAfterTest();
        $s = $this->linked_setup();
        // A framework of the organisation whose default grade (what "complete" gives) is not a proficient one.
        $scale = $this->getDataGenerator()->create_scale(['scale' => 'Nao,Sim']);
        $generator = $this->getDataGenerator()->get_plugin_generator('core_competency');
        $framework = $generator->create_framework(['scaleid' => $scale->id, 'scaleconfiguration' => [
            ['scaleid' => $scale->id],
            ['id' => 1, 'scaledefault' => 1, 'proficient' => 0],
            ['id' => 2, 'scaledefault' => 0, 'proficient' => 1],
        ]]);
        $competency = $generator->create_competency(['competencyframeworkid' => $framework->get('id')]);
        $result = set_course_competencies::execute((int) $s->course->id, [
            ['competencyid' => (int) $competency->get('id'), 'cmids' => [(int) $s->pages[1]->cmid]],
            ['competencyid' => $s->ids['c1h1'], 'cmids' => []],
        ]);
        $this->assertFalse($result['links'][0]['completeachieves'], 'the framework of the organisation: it would not achieve');
        $this->assertTrue($result['links'][1]['completeachieves'], 'the control: the framework the plugin made does');
    }

    public function test_linking_needs_the_permission_in_the_course(): void {
        global $DB;
        $this->resetAfterTest();
        $s = $this->linked_setup();
        $links = [['competencyid' => $s->ids['c1h1'], 'cmids' => [(int) $s->pages[1]->cmid]]];
        // The account with the permissions of Moodle but not the plugin's is refused by the plugin (the API alone would let it).
        $this->setUser($this->moodle_only_account());
        try {
            set_course_competencies::execute((int) $s->course->id, $links);
            $this->fail('linked with the permissions of Moodle but not the plugin\'s');
        } catch (\required_capability_exception $e) {
            $this->assertSame(0, $DB->count_records('competency_coursecomp', ['courseid' => $s->course->id]));
        }
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        set_course_competencies::execute((int) $s->course->id, $links);
    }
}
