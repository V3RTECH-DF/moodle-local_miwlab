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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_courses_overview::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\courses_overview::class)]
/**
 * Tests of the batch read of the courses of some categories.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_courses_overview
 * @covers     \local_miwlab\local\courses_overview
 */
final class courses_overview_test extends \advanced_testcase {
    /**
     * The integration account: only local/miwlab:viewreports, given in the context asked (the site by default).
     *
     * @param \context|null $context
     * @return \stdClass
     */
    protected function reader(?\context $context = null): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Leitura', 'leitura' . random_int(1, 999999), '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
        $context = $context ?? \context_system::instance();
        assign_capability('local/miwlab:viewreports', CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * Calls the function and cleans the answer as the web service layer does.
     *
     * @param array $categoryids
     * @param bool $subcategories
     * @param int $from
     * @param int $num
     * @return array
     */
    protected function read(array $categoryids, bool $subcategories = true, int $from = 0, int $num = 500): array {
        return base::clean_returnvalue(
            get_courses_overview::execute_returns(),
            get_courses_overview::execute($categoryids, $subcategories, $from, $num)
        );
    }

    /**
     * The rows of the answer by course id.
     *
     * @param array $answer
     * @return array
     */
    protected function by_id(array $answer): array {
        return array_column($answer['courses'], null, 'id');
    }

    /**
     * Marks an activity complete for a user.
     *
     * @param \stdClass $course
     * @param \stdClass $module
     * @param int $userid
     * @param int $state
     */
    protected function complete(\stdClass $course, \stdClass $module, int $userid, int $state = COMPLETION_COMPLETE): void {
        $cm = get_fast_modinfo($course)->get_cm($module->cmid);
        $completion = new \completion_info($course);
        $current = $completion->get_data($cm, false, $userid);
        $current->completionstate = $state;
        $current->timemodified = time();
        $completion->internal_set_data($cm, $current);
    }

    /**
     * Gives the course one completion criterion (an activity), which is what makes the completion of the course count.
     *
     * @param \stdClass $course
     * @param \stdClass $module
     */
    protected function add_criteria(\stdClass $course, \stdClass $module): void {
        global $DB;
        $DB->insert_record('course_completion_criteria', (object) [
            'course' => $course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page',
            'moduleinstance' => $module->cmid,
        ]);
    }

    /**
     * Marks the course complete for a user (the record that the completion cron would write).
     *
     * @param \stdClass $course
     * @param int $userid
     */
    protected function complete_course(\stdClass $course, int $userid): void {
        global $DB;
        $DB->insert_record('course_completions', (object) [
            'userid' => $userid,
            'course' => $course->id,
            'timeenrolled' => time() - 100,
            'timestarted' => time() - 90,
            'timecompleted' => time() - 10,
        ]);
    }

    /**
     * The students who have every tracked activity complete, counted the slow way: one student at a time, through completion_info.
     *
     * @param \stdClass $course
     * @return int
     */
    protected function students_with_everything_complete(\stdClass $course): int {
        $context = \context_course::instance($course->id);
        [$sql, $params] = \local_miwlab\local\completion_report::students_sql($context);
        global $DB;
        $userids = $DB->get_fieldset_sql($sql, $params);
        $completion = new \completion_info($course);
        $cms = array_filter(get_fast_modinfo($course)->get_cms(), function ($cm): bool {
            return $cm->completion != COMPLETION_TRACKING_NONE && empty($cm->deletioninprogress);
        });
        $total = 0;
        foreach ($userids as $userid) {
            $all = true;
            foreach ($cms as $cm) {
                $state = $completion->get_data($cm, false, $userid)->completionstate;
                if ($state != COMPLETION_COMPLETE && $state != COMPLETION_COMPLETE_PASS) {
                    $all = false;
                }
            }
            $total += $all ? 1 : 0;
        }
        return $total;
    }

    /**
     * Four courses of a category and one of a subcategory, with the kinds of people that must not be counted, and the numbers
     * compared with those of completion_report, which is what the course-by-course read answers.
     */
    public function test_the_batch_matches_the_course_by_course_report(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $top = $generator->create_category(['name' => 'Top']);
        $sub = $generator->create_category(['name' => 'Sub', 'parent' => $top->id]);
        $other = $generator->create_category(['name' => 'Other']);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];

        // A: completion by activities, with the people that must not count.
        $a = $generator->create_course(['category' => $top->id, 'enablecompletion' => 1]);
        $pa1 = $generator->create_module('page', ['course' => $a->id] + $manual);
        $pa2 = $generator->create_module('page', ['course' => $a->id] + $manual);
        $studentsa = [];
        for ($i = 0; $i < 5; $i++) {
            $studentsa[] = $generator->create_and_enrol($a, 'student');
        }
        $teacher = $generator->create_and_enrol($a, 'editingteacher');
        $suspended = $generator->create_and_enrol($a, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        $expired = $generator->create_and_enrol($a, 'student', null, 'manual', 0, time() - 3600);
        $deleted = $generator->create_and_enrol($a, 'student');
        // Enrolled with no role in the course: the role of student is given in the CATEGORY, above the course.
        $above = $generator->create_user();
        $generator->enrol_user($above->id, $a->id, null);
        // The enrolment plugin gives its default role in the course; take it away, so that the only student role is the one above.
        role_unassign_all(['userid' => $above->id, 'contextid' => \context_course::instance($a->id)->id]);
        $studentrole = $DB->get_field('role', 'id', ['shortname' => 'student']);
        role_assign($studentrole, $above->id, \context_coursecat::instance($top->id)->id);
        foreach ([$studentsa[0], $studentsa[1], $studentsa[2], $teacher, $suspended, $expired, $deleted, $above] as $user) {
            $this->complete($a, $pa1, $user->id);
            $this->complete($a, $pa2, $user->id);
        }
        $this->complete($a, $pa1, $studentsa[3]->id);
        $this->complete($a, $pa2, $studentsa[3]->id, COMPLETION_COMPLETE_FAIL);
        // A deleted account (the row stays, as Moodle keeps it; delete_user() would also drop the enrolment and hide the case).
        $DB->set_field('user', 'deleted', 1, ['id' => $deleted->id]);

        // B: with completion criteria in the course; the course completion counts, not the activities.
        $b = $generator->create_course(['category' => $sub->id, 'enablecompletion' => 1]);
        $pb = $generator->create_module('page', ['course' => $b->id] + $manual);
        $this->add_criteria($b, $pb);
        $studentsb = [];
        for ($i = 0; $i < 3; $i++) {
            $studentsb[] = $generator->create_and_enrol($b, 'student');
        }
        $teacherb = $generator->create_and_enrol($b, 'teacher');
        $this->complete_course($b, $studentsb[0]->id);
        $this->complete_course($b, $studentsb[1]->id);
        $this->complete_course($b, $teacherb->id);

        // C: completion on but nothing tracked, no criteria: it does not measure.
        $c = $generator->create_course(['category' => $top->id, 'enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $c->id]);
        $generator->create_and_enrol($c, 'student');

        // D: completion off in the course, with a tracked activity: it does not measure.
        $d = $generator->create_course(['category' => $top->id, 'enablecompletion' => 0]);
        // Born with completion off in the course, the activity is set to track completion in the table (the generator refuses it).
        $pd = $generator->create_module('page', ['course' => $d->id]);
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_MANUAL, ['id' => $pd->cmid]);
        $generator->create_and_enrol($d, 'student');
        $generator->create_and_enrol($d, 'student');

        // F: an enrolment method turned off: its students are not active.
        $f = $generator->create_course(['category' => $top->id, 'enablecompletion' => 1]);
        $generator->create_and_enrol($f, 'student');
        $generator->create_and_enrol($f, 'student');
        $DB->set_field('enrol', 'status', ENROL_INSTANCE_DISABLED, ['courseid' => $f->id, 'enrol' => 'manual']);

        // G: the only tracked activity is being deleted: the course does not measure.
        $g = $generator->create_course(['category' => $top->id, 'enablecompletion' => 1]);
        $pg = $generator->create_module('page', ['course' => $g->id] + $manual);
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $pg->cmid]);

        // E: in another category: not read.
        $e = $generator->create_course(['category' => $other->id, 'enablecompletion' => 1]);
        $generator->create_and_enrol($e, 'student');

        $this->setUser($this->reader());
        $result = $this->read([$top->id]);
        $rows = $this->by_id($result);

        $this->assertSame(6, $result['total']);
        $this->assertSame(-1, $result['next']);
        $expected = [(int) $a->id, (int) $b->id, (int) $c->id, (int) $d->id, (int) $f->id, (int) $g->id];
        $this->assertEqualsCanonicalizing($expected, array_keys($rows));
        $this->assertSame(0, $rows[(int) $f->id]['students'], 'The enrolment method is off');
        $this->assertFalse($rows[(int) $g->id]['measures'], 'The tracked activity is being deleted');
        $this->assertSame(0, $rows[(int) $g->id]['trackedactivities']);
        $this->assertArrayNotHasKey((int) $e->id, $rows);

        // The students and the completion of the course are those of completion_report, course by course.
        foreach ([$a, $b, $c, $d, $f, $g] as $course) {
            $one = \local_miwlab\local\completion_report::build($course);
            $this->assertSame($one['students'], $rows[(int) $course->id]['students'], "Students of $course->shortname");
            $this->assertSame($one['completionenabled'], $rows[(int) $course->id]['completionenabled']);
        }
        $reportb = \local_miwlab\local\completion_report::build($b);
        $this->assertSame(2, $reportb['coursecompletion']['completed'], 'The teacher is not counted');
        $this->assertSame($reportb['coursecompletion']['completed'], $rows[(int) $b->id]['completed']);
        $this->assertSame('course', $rows[(int) $b->id]['completedby']);
        $this->assertSame(3, $rows[(int) $b->id]['students']);

        // A: five students plus the one with the role above the course; the teacher, the suspended, the expired and the
        // deleted are out.
        $this->assertSame(6, $rows[(int) $a->id]['students']);
        $this->assertTrue($rows[(int) $a->id]['measures']);
        $this->assertSame('activities', $rows[(int) $a->id]['completedby']);
        $this->assertSame(2, $rows[(int) $a->id]['trackedactivities']);
        // Three students and the one with the role above completed both; the student with a failed one did not.
        $this->assertSame(4, $rows[(int) $a->id]['completed']);
        $this->assertSame($this->students_with_everything_complete($a), $rows[(int) $a->id]['completed']);

        $this->assertFalse($rows[(int) $c->id]['measures'], 'Completion on, nothing tracked and no criteria');
        $this->assertSame('none', $rows[(int) $c->id]['completedby']);
        $this->assertSame(0, $rows[(int) $c->id]['completed']);
        $this->assertSame(1, $rows[(int) $c->id]['students'], 'It still has students');

        $this->assertFalse($rows[(int) $d->id]['measures'], 'Completion off in the course');
        $this->assertSame(0, $rows[(int) $d->id]['trackedactivities']);
        $this->assertSame(2, $rows[(int) $d->id]['students']);
        $this->assertSame((int) $sub->id, $rows[(int) $b->id]['categoryid']);

        // Nothing that identifies a person.
        $json = json_encode($result);
        foreach (array_merge($studentsa, $studentsb, [$teacher, $suspended, $expired, $above]) as $user) {
            $this->assertStringNotContainsString($user->username, $json);
            $this->assertStringNotContainsString($user->email, $json);
        }
        $this->assertDoesNotMatchRegularExpression('/"(userid|user|users|username|email)"/', $json);
    }

    public function test_only_the_students_complete_and_the_teacher_does_not(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL]);
        $teachers = [];
        for ($i = 0; $i < 3; $i++) {
            $teachers[] = $generator->create_and_enrol($course, 'editingteacher');
        }
        $student = $generator->create_and_enrol($course, 'student');
        foreach ($teachers as $teacher) {
            $this->complete($course, $page, $teacher->id);
        }
        $this->complete($course, $page, $student->id);
        $this->setUser($this->reader());
        $row = $this->by_id($this->read([$category->id]))[(int) $course->id];
        $this->assertSame(1, $row['students']);
        $this->assertSame(1, $row['completed'], 'The three teachers who completed are not students');
    }

    public function test_the_subcategories_are_read_unless_the_call_says_otherwise(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $top = $generator->create_category();
        $sub = $generator->create_category(['parent' => $top->id]);
        $deep = $generator->create_category(['parent' => $sub->id]);
        $sibling = $generator->create_category();
        $courses = [
            $generator->create_course(['category' => $top->id]),
            $generator->create_course(['category' => $sub->id]),
            $generator->create_course(['category' => $deep->id]),
        ];
        $generator->create_course(['category' => $sibling->id]);
        $this->setUser($this->reader());

        $all = $this->read([$top->id]);
        $this->assertSame(3, $all['total']);
        $this->assertEqualsCanonicalizing(array_map('intval', array_column($courses, 'id')), array_column($all['courses'], 'id'));

        $own = $this->read([$top->id], false);
        $this->assertSame([(int) $courses[0]->id], array_column($own['courses'], 'id'));

        // A name that starts like another id does not drag the other category: 1 and 11, 12...
        $this->assertSame(1, $this->read([$sibling->id])['total']);
    }

    public function test_pages_follow_one_another_until_the_last(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = (int) $generator->create_course(['category' => $category->id])->id;
        }
        $this->setUser($this->reader());

        $first = $this->read([$category->id], true, 0, 2);
        $this->assertSame(5, $first['total']);
        $this->assertSame(2, $first['next']);
        $this->assertSame(array_slice($ids, 0, 2), array_column($first['courses'], 'id'));
        $second = $this->read([$category->id], true, $first['next'], 2);
        $this->assertSame(4, $second['next']);
        $last = $this->read([$category->id], true, $second['next'], 2);
        $this->assertSame(-1, $last['next'], 'The last page says there is no next');
        $this->assertSame([$ids[4]], array_column($last['courses'], 'id'));
        $this->assertSame($ids, array_merge(
            array_column($first['courses'], 'id'),
            array_column($second['courses'], 'id'),
            array_column($last['courses'], 'id')
        ));
    }

    public function test_the_capability_is_checked_in_the_context_of_each_category(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $allowed = $generator->create_category();
        $sub = $generator->create_category(['parent' => $allowed->id]);
        $forbidden = $generator->create_category();
        $generator->create_course(['category' => $allowed->id]);
        $generator->create_course(['category' => $sub->id]);
        $generator->create_course(['category' => $forbidden->id]);

        // The role only in one category: it reaches the subcategory below it, not the other category.
        $this->setUser($this->reader(\context_coursecat::instance($allowed->id)));
        $this->assertSame(2, $this->read([$allowed->id])['total']);
        $this->assertSame(1, $this->read([$sub->id])['total']);
        try {
            $this->read([$forbidden->id]);
            $this->fail('A category without the capability should be refused');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        // One category refused refuses the whole call: nothing is read from the allowed one either.
        try {
            $this->read([$allowed->id, $forbidden->id]);
            $this->fail('The call with one refused category should be refused');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
    }

    public function test_a_subcategory_where_the_capability_is_prohibited_stays_out(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $parent = $generator->create_category();
        $open = $generator->create_category(['parent' => $parent->id]);
        $closed = $generator->create_category(['parent' => $parent->id]);
        $deep = $generator->create_category(['parent' => $closed->id]);
        $generator->create_course(['category' => $parent->id]);
        $generator->create_course(['category' => $open->id]);
        $closedcourse = $generator->create_course(['category' => $closed->id]);
        $deepcourse = $generator->create_course(['category' => $deep->id]);

        $user = $this->reader(\context_coursecat::instance($parent->id));
        $role = $generator->create_role();
        set_role_contextlevels($role, [CONTEXT_COURSECAT]);
        assign_capability('local/miwlab:viewreports', CAP_PROHIBIT, $role, \context_coursecat::instance($closed->id)->id, true);
        role_assign($role, $user->id, \context_coursecat::instance($closed->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);

        $answer = $this->read([$parent->id]);
        $rows = $this->by_id($answer);
        $this->assertSame(2, $answer['total']);
        $this->assertSame(2, $answer['categoriesleftout']);
        $this->assertArrayNotHasKey((int) $closedcourse->id, $rows);
        $this->assertArrayNotHasKey((int) $deepcourse->id, $rows);
        // Control: an account with the right everywhere reads all four and leaves nothing out.
        $this->setUser($this->reader());
        $all = $this->read([$parent->id]);
        $this->assertSame(4, $all['total']);
        $this->assertSame(0, $all['categoriesleftout']);
    }

    public function test_an_account_without_the_capability_is_refused(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id]);
        $this->setUser($generator->create_and_enrol($course, 'editingteacher'));
        $this->expectException(\required_capability_exception::class);
        get_courses_overview::execute([$category->id]);
    }

    public function test_a_missing_category_and_the_front_page_are_not_read(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        try {
            get_courses_overview::execute([987654]);
            $this->fail('A category that does not exist should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:categorynotfound', $e->errorcode);
        }
        // The front page course (SITEID) lives in category 0 and never shows up.
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $generator->create_course(['category' => $category->id]);
        $ids = array_column($this->read([$category->id])['courses'], 'id');
        $this->assertNotContains((int) SITEID, $ids);
        $this->expectException(\invalid_parameter_exception::class);
        get_courses_overview::execute([]);
    }
}
