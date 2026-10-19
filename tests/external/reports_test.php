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

/**
 * Tests of the consolidated reads: completion, feedbacks and the analysis of an anonymous feedback, and finding by marker.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_course_completion
 * @covers     \local_miwlab\external\get_course_feedbacks
 * @covers     \local_miwlab\external\get_feedback_analysis
 * @covers     \local_miwlab\external\find_courses
 * @covers     \local_miwlab\local\completion_report
 * @covers     \local_miwlab\local\feedback_report
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_course_completion::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_course_feedbacks::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_feedback_analysis::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\find_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\completion_report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\feedback_report::class)]
final class reports_test extends \advanced_testcase {
    /**
     * The integration account: only local/miwlab:viewreports, at site level. Not enrolled anywhere.
     *
     * @return \stdClass
     */
    protected function reader(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Leitura', 'leitura', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $system = \context_system::instance();
        assign_capability('local/miwlab:viewreports', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
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

    public function test_completion_counts_only_active_students(): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $this->resetAfterTest();
        $CFG->enablecompletion = 1;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1, 'numsections' => 2], ['createsections' => true]);
        $manual = ['completion' => COMPLETION_TRACKING_MANUAL];
        $a = $generator->create_module('page', ['course' => $course->id, 'section' => 1] + $manual);
        $b = $generator->create_module('page', ['course' => $course->id, 'section' => 1] + $manual);
        $c = $generator->create_module('page', ['course' => $course->id, 'section' => 2] + $manual);
        $generator->create_module('page', ['course' => $course->id, 'section' => 2, 'completion' => COMPLETION_TRACKING_NONE]);
        $students = [];
        for ($i = 0; $i < 4; $i++) {
            $students[] = $generator->create_and_enrol($course, 'student');
        }
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $suspended = $generator->create_and_enrol($course, 'student', null, 'manual', 0, 0, ENROL_USER_SUSPENDED);
        foreach ([$students[0], $students[1], $students[2], $teacher, $suspended] as $user) {
            $this->complete($course, $a, $user->id);
        }
        foreach ([$students[0], $students[1], $teacher, $suspended] as $user) {
            $this->complete($course, $b, $user->id, COMPLETION_COMPLETE_PASS);
        }
        $this->complete($course, $b, $students[2]->id, COMPLETION_COMPLETE_FAIL);
        $this->complete($course, $c, $students[3]->id);

        $this->setUser($this->reader());
        $result = base::clean_returnvalue(
            get_course_completion::execute_returns(),
            get_course_completion::execute($course->id)
        );

        $this->assertTrue($result['completionenabled']);
        $this->assertSame(4, $result['students']);
        $byid = array_column($result['activities'], null, 'cmid');
        $this->assertCount(3, $byid, 'Only activities with completion tracking');
        $this->assertSame(3, $byid[$a->cmid]['completed']);
        $this->assertSame(2, $byid[$b->cmid]['completed']);
        $this->assertSame(1, $byid[$b->cmid]['completedfail']);
        $this->assertSame(1, $byid[$c->cmid]['completed']);
        $bynumber = array_column($result['sections'], null, 'number');
        $this->assertSame(2, $bynumber[1]['activities']);
        $this->assertSame(2, $bynumber[1]['completed'], 'Section 1: both activities, without the failed one');
        $this->assertSame(1, $bynumber[2]['activities']);
        $this->assertSame(1, $bynumber[2]['completed']);
        $this->assertSame(0, $bynumber[0]['completed']);

        // Nothing that identifies a person.
        $json = json_encode($result);
        foreach (array_merge($students, [$teacher, $suspended]) as $user) {
            $this->assertStringNotContainsString($user->username, $json);
            $this->assertStringNotContainsString($user->email, $json);
        }
        $this->assertDoesNotMatchRegularExpression('/"(userid|user|users|username|email)"/', $json);
    }

    public function test_reads_need_the_capability_of_the_plugin(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        // A teacher of the course, without local/miwlab:viewreports.
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->expectException(\required_capability_exception::class);
        get_course_completion::execute($course->id);
    }

    /**
     * A feedback with a rated question, a multiple choice and an open answer, answered by $count students.
     *
     * @param \stdClass $course
     * @param int $anonymous
     * @param int $count
     * @return \stdClass The feedback.
     */
    protected function answered_feedback(\stdClass $course, int $anonymous, int $count): \stdClass {
        $generator = $this->getDataGenerator();
        $fg = $generator->get_plugin_generator('mod_feedback');
        $feedback = $fg->create_instance(['course' => $course->id, 'anonymous' => $anonymous]);
        $fg->create_item_label($feedback, ['label' => '[miwlab:pesquisa:c:curso-1:-:v1]']);
        $fg->create_item_multichoicerated($feedback, ['name' => 'Claro', 'label' => '[miwlab:c:claro:v1]',
            'values' => "1/Pouco\n2/Médio\n3/Muito"]);
        $fg->create_item_multichoice($feedback, ['name' => 'Formato', 'values' => "Texto\nVídeo"]);
        $fg->create_item_textarea($feedback, ['name' => 'Comentário']);
        $cm = get_coursemodule_from_instance('feedback', $feedback->id);
        for ($i = 0; $i < $count; $i++) {
            $student = $generator->create_and_enrol($course, 'student');
            $fg->create_response(['cmid' => $cm->id, 'userid' => $student->id, 'anonymous' => $anonymous == 1 ? 'true' : 'false',
                'Claro' => ['Pouco', 'Médio', 'Muito'][$i % 3], 'Formato' => ['Texto', 'Vídeo'][$i % 2],
                'Comentário' => 'Resposta ' . chr(ord('z') - $i)]);
        }
        return $feedback;
    }

    public function test_feedback_analysis_withholds_text_unless_asked(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $feedback = $this->answered_feedback($course, FEEDBACK_ANONYMOUS_YES, 6);
        $this->setUser($this->reader());

        $list = base::clean_returnvalue(get_course_feedbacks::execute_returns(), get_course_feedbacks::execute($course->id));
        $this->assertCount(1, $list['feedbacks']);
        $this->assertTrue($list['feedbacks'][0]['anonymous']);
        $this->assertSame('[miwlab:pesquisa:c:curso-1:-:v1]', $list['feedbacks'][0]['items'][0]['label']);

        $without = base::clean_returnvalue(
            get_feedback_analysis::execute_returns(),
            get_feedback_analysis::execute($feedback->id)
        );
        $this->assertSame(6, $without['completedcount']);
        $this->assertFalse($without['textincluded']);
        $types = array_map(function (array $d): string {
            return $d['item']['typ'];
        }, $without['itemsdata']);
        $data = array_combine($types, $without['itemsdata']);
        $this->assertTrue($data['textarea']['withheld']);
        $this->assertSame([], $data['textarea']['data']);
        $this->assertSame(6, $data['textarea']['answercount'], 'How many answered leaves, without the text');
        $this->assertStringNotContainsString('Resposta', json_encode($without));
        $counts = array_map(function (string $d): int {
            return (int) json_decode($d)->answercount;
        }, $data['multichoicerated']['data']);
        $this->assertSame(6, array_sum($counts));
        $this->assertSame([2, 2, 2], $counts);

        $with = base::clean_returnvalue(
            get_feedback_analysis::execute_returns(),
            get_feedback_analysis::execute($feedback->id, true)
        );
        $types = array_map(function (array $d): string {
            return $d['item']['typ'];
        }, $with['itemsdata']);
        $data = array_combine($types, $with['itemsdata']);
        $this->assertTrue($with['textincluded']);
        $this->assertFalse($data['textarea']['withheld']);
        $this->assertSame(
            ['Resposta u', 'Resposta v', 'Resposta w', 'Resposta x', 'Resposta y', 'Resposta z'],
            $data['textarea']['data'],
            'Every open answer, in alphabetical order, not in the order they arrived'
        );
    }

    public function test_feedback_that_is_not_anonymous_is_refused(): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $feedback = $this->answered_feedback($course, FEEDBACK_ANONYMOUS_NO, 2);
        $this->setUser($this->reader());
        try {
            get_feedback_analysis::execute($feedback->id, true);
            $this->fail('A feedback that is not anonymous should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:notanonymous', $e->errorcode);
        }
    }

    public function test_find_courses_by_marker(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $one = $generator->create_course(['idnumber' => 'miwlab:curso-1:v1']);
        $again = $generator->create_course(['idnumber' => 'miwlab:curso-1:v1:99']);
        $other = $generator->create_course(['idnumber' => 'miwlab:curso-10:v1']);
        $generator->create_course(['idnumber' => 'curso-1']);
        $this->setUser($this->reader());

        $found = base::clean_returnvalue(find_courses::execute_returns(), find_courses::execute('miwlab:curso-1'));
        $ids = array_column($found['courses'], 'id');
        sort($ids);
        $this->assertSame([(int) $one->id, (int) $again->id], $ids, 'The prefix needs ":" after it: curso-10 is not curso-1');
        $this->assertNotContains((int) $other->id, $ids);

        $found = find_courses::execute('miwlab:curso-1:v1');
        $this->assertCount(2, $found['courses']);

        $this->setUser($generator->create_user());
        $this->assertSame([], find_courses::execute('miwlab:curso-1')['courses']);
    }
}
