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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\stamp_activities::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\find_courses_by_stamp::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\stamps::class)]
/**
 * Tests of the stamp of the activities (issue 254): writing it into a course that was already in Moodle without ever
 * overwriting a value that is there, and finding the courses that carry it.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\stamp_activities
 * @covers     \local_miwlab\external\find_courses_by_stamp
 * @covers     \local_miwlab\local\stamps
 */
final class stamps_test extends \advanced_testcase {
    /**
     * An account with only the capabilities of the plugin that these functions ask for, at site level.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function account(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Selo', 'selo' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $system = \context_system::instance();
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A course with some pages, the first ones without ID number.
     *
     * @param int $pages
     * @return \stdClass course, cm => cmid list
     */
    protected function course_with_pages(int $pages = 3): \stdClass {
        $generator = $this->getDataGenerator();
        $result = new \stdClass();
        $result->course = $generator->create_course();
        $result->cm = [];
        for ($i = 1; $i <= $pages; $i++) {
            $page = $generator->create_module('page', ['course' => $result->course->id, 'name' => 'Pagina ' . $i]);
            $result->cm[$i] = (int) $page->cmid;
        }
        return $result;
    }

    /**
     * The ID number of a course module, straight from the database.
     *
     * @param int $cmid
     * @return string
     */
    protected function idnumber(int $cmid): string {
        global $DB;
        return (string) $DB->get_field('course_modules', 'idnumber', ['id' => $cmid]);
    }

    public function test_stamps_the_empty_ones_and_the_reads_see_it(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages();
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        $result = stamp_activities::clean_returnvalue(stamp_activities::execute_returns(), stamp_activities::execute(
            (int) $c->course->id,
            [['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1'], ['cmid' => $c->cm[2], 'stamp' => 'miwlab:curso-1:a:atv-2']]
        ));
        $this->assertSame(2, $result['writtencount']);
        $this->assertSame(0, $result['skippedcount']);
        $this->assertSame('miwlab:curso-1:a:atv-1', $this->idnumber($c->cm[1]));
        $this->assertSame('miwlab:curso-1:a:atv-2', $this->idnumber($c->cm[2]));
        $this->assertSame('', $this->idnumber($c->cm[3]), 'what was not asked is not touched');
        $this->assertSame('miwlab:curso-1:a:atv-1', get_fast_modinfo($c->course)->get_cm($c->cm[1])->idnumber, 'cache rebuilt');
    }

    public function test_never_overwrites_a_value_that_is_there(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages();
        set_coursemodule_idnumber($c->cm[1], 'codigo-da-organizacao');
        set_coursemodule_idnumber($c->cm[2], 'miwlab:curso-1:a:outra');
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        $result = stamp_activities::execute((int) $c->course->id, [
            ['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1'],
            ['cmid' => $c->cm[2], 'stamp' => 'miwlab:curso-1:a:atv-2'],
            ['cmid' => $c->cm[3], 'stamp' => 'miwlab:curso-1:a:atv-3'],
        ]);
        // The control: the empty one is stamped in the same call, so the refusals are not a call that refuses everything.
        $this->assertSame([['cmid' => $c->cm[3], 'stamp' => 'miwlab:curso-1:a:atv-3']], $result['written']);
        $this->assertSame('codigo-da-organizacao', $this->idnumber($c->cm[1]));
        $this->assertSame('miwlab:curso-1:a:outra', $this->idnumber($c->cm[2]));
        $this->assertSame('miwlab:curso-1:a:atv-3', $this->idnumber($c->cm[3]));
        $skipped = array_column($result['skipped'], null, 'cmid');
        $this->assertSame('existing', $skipped[$c->cm[1]]['reason']);
        $this->assertSame('codigo-da-organizacao', $skipped[$c->cm[1]]['current']);
        $this->assertSame('existing', $skipped[$c->cm[2]]['reason']);
    }

    public function test_the_same_stamp_again_is_told_and_changes_nothing(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages(1);
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        $items = [['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1']];
        $this->assertCount(1, stamp_activities::execute((int) $c->course->id, $items)['written']);
        $again = stamp_activities::execute((int) $c->course->id, $items);
        $this->assertSame([], $again['written']);
        $this->assertSame('same', $again['skipped'][0]['reason']);
        $this->assertSame('miwlab:curso-1:a:atv-1', $this->idnumber($c->cm[1]));
    }

    public function test_a_stamp_is_not_put_in_two_activities_of_the_course(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $this->resetAfterTest();
        $c = $this->course_with_pages();
        // A grade item of the course that already uses one of the stamps.
        $item = new \grade_item(['courseid' => $c->course->id, 'itemtype' => 'manual', 'itemname' => 'Nota',
            'idnumber' => 'miwlab:curso-1:a:atv-9'], false);
        $item->insert();
        set_coursemodule_idnumber($c->cm[3], 'miwlab:curso-1:a:atv-5');
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        $result = stamp_activities::execute((int) $c->course->id, [
            ['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-5'],
            ['cmid' => $c->cm[2], 'stamp' => 'miwlab:curso-1:a:atv-9'],
            ['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1'],
        ]);
        $skipped = array_column($result['skipped'], 'reason', 'stamp');
        $this->assertSame('duplicated', $skipped['miwlab:curso-1:a:atv-5'], 'another activity already has it');
        $this->assertSame('idnumbertaken', $skipped['miwlab:curso-1:a:atv-9'], 'a grade item has it');
        $this->assertSame('duplicated', $skipped['miwlab:curso-1:a:atv-1'], 'an activity named twice is decided at the first time');
        $this->assertSame('', $this->idnumber($c->cm[1]));
        $this->assertSame('', $this->idnumber($c->cm[2]));
    }

    public function test_refuses_a_malformed_stamp_and_an_activity_of_another_course(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages(1);
        $other = $this->course_with_pages(1);
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        $result = stamp_activities::execute((int) $c->course->id, [
            ['cmid' => $c->cm[1], 'stamp' => 'curso-1'],
            ['cmid' => $other->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1'],
            ['cmid' => 999999, 'stamp' => 'miwlab:curso-1:a:atv-2'],
        ]);
        $this->assertSame([], $result['written']);
        $this->assertSame(['invalidstamp', 'notincourse', 'notincourse'], array_column($result['skipped'], 'reason'));
        $this->assertSame('', $this->idnumber($c->cm[1]));
        $this->assertSame('', $this->idnumber($other->cm[1]), 'the activity of another course is never written');
    }

    public function test_needs_the_permission_in_the_course(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages(1);
        $this->setUser($this->account(['local/miwlab:viewreports']));
        try {
            stamp_activities::execute((int) $c->course->id, [['cmid' => $c->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1']]);
            $this->fail('stamped without the permission');
        } catch (\required_capability_exception $e) {
            $this->assertSame('', $this->idnumber($c->cm[1]));
        }
        $this->setUser($this->account(['local/miwlab:updatecourse']));
        try {
            stamp_activities::execute((int) SITEID, []);
            $this->fail('the site is not a course');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:coursenotfound', $e->errorcode);
        }
    }

    public function test_finds_the_course_and_its_copies_by_the_stamp(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $original = $this->course_with_pages(2);
        $copy = $this->course_with_pages(2);
        $unrelated = $this->course_with_pages(1);
        $bare = $generator->create_course(['idnumber' => 'miwlab:curso-1:v2']);
        set_coursemodule_idnumber($original->cm[1], 'miwlab:curso-1:a:atv-1');
        set_coursemodule_idnumber($original->cm[2], 'miwlab:curso-1:a:atv-2');
        set_coursemodule_idnumber($copy->cm[1], 'miwlab:curso-1:a:atv-1');
        set_coursemodule_idnumber($unrelated->cm[1], 'miwlab:curso-10:a:atv-1');
        global $DB;
        $DB->set_field('course', 'idnumber', 'miwlab:curso-1:v2', ['id' => $original->course->id]);

        $this->setUser($this->account(['local/miwlab:viewreports']));
        $found = find_courses_by_stamp::clean_returnvalue(
            find_courses_by_stamp::execute_returns(),
            find_courses_by_stamp::execute('curso-1')
        );
        $byid = array_column($found['courses'], null, 'id');
        $this->assertSame([(int) $original->course->id, (int) $copy->course->id], array_keys($byid));
        $this->assertSame(2, $byid[$original->course->id]['stampedcount']);
        $this->assertTrue($byid[$original->course->id]['marked']);
        $this->assertSame(1, $byid[$copy->course->id]['stampedcount']);
        $this->assertFalse($byid[$copy->course->id]['marked'], 'the copy lost the marker of the course and kept the stamps');
        $this->assertSame([['cmid' => $copy->cm[1], 'stamp' => 'miwlab:curso-1:a:atv-1']], $byid[$copy->course->id]['stamps']);
        $this->assertSame(2, $found['total']);
        $this->assertNotContains((int) $unrelated->course->id, array_keys($byid), 'curso-10 is not curso-1');
        $this->assertNotContains((int) $bare->id, array_keys($byid), 'the marker alone is not a stamp');

        $counts = find_courses_by_stamp::execute('curso-1', false);
        $this->assertSame([], $counts['courses'][0]['stamps']);
        $this->assertSame(2, $counts['courses'][0]['stampedcount']);
    }

    public function test_the_underscore_of_an_identifier_is_not_a_wildcard(): void {
        $this->resetAfterTest();
        $one = $this->course_with_pages(1);
        $two = $this->course_with_pages(1);
        set_coursemodule_idnumber($one->cm[1], 'miwlab:a_b:a:atv-1');
        set_coursemodule_idnumber($two->cm[1], 'miwlab:axb:a:atv-1');
        $this->setUser($this->account(['local/miwlab:viewreports']));
        $this->assertSame([(int) $one->course->id], array_column(find_courses_by_stamp::execute('a_b')['courses'], 'id'));
    }

    public function test_only_courses_the_account_can_read_and_a_malformed_identifier_is_refused(): void {
        $this->resetAfterTest();
        $c = $this->course_with_pages(1);
        set_coursemodule_idnumber($c->cm[1], 'miwlab:curso-1:a:atv-1');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], find_courses_by_stamp::execute('curso-1')['courses']);
        $this->setUser($this->account(['local/miwlab:viewreports']));
        $this->assertCount(1, find_courses_by_stamp::execute('curso-1')['courses'], 'the control: the reader sees it');
        try {
            find_courses_by_stamp::execute('curso-1:a');
            $this->fail('accepted an identifier with a colon');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidmarker', $e->errorcode);
        }
    }
}
