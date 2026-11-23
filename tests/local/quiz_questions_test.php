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

use local_miwlab\external\get_info;
use local_miwlab\external\get_question_state;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\quiz_questions::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\quiz_questions_writer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_question_state::class)]
/**
 * Tests of the questions of the MiwLab added to a quiz that is in Moodle (1.15.0, issue 349): each one goes once, a quiz with
 * attempts is never touched, nothing is deleted from the question bank, with a draw there is one set, and a failure leaves the
 * quiz as it was.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\quiz_questions
 * @covers     \local_miwlab\local\quiz_questions_writer
 * @covers     \local_miwlab\external\get_question_state
 */
final class quiz_questions_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;
    /** @var \stdClass The quiz. */
    protected $quiz;
    /** @var \context_module The context of the quiz. */
    protected $context;

    /**
     * A course with a quiz without questions that students cannot start (hidden), as an administrator.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $this->course = $g->create_course();
        $this->quiz = $g->create_module(
            'quiz',
            ['course' => $this->course->id, 'grade' => 10, 'questionsperpage' => 0],
            ['visible' => 0]
        );
        $this->context = \context_module::instance($this->quiz->cmid);
    }

    /**
     * Tidies the hook after each test.
     */
    protected function tearDown(): void {
        update_runner::$stephook = null;
        parent::tearDown();
    }

    /**
     * A question made in Moodle by the course team, in a context, in the quiz or only in the bank.
     *
     * @param string $text
     * @param \context $context
     * @param bool $inquiz
     * @return int The question id.
     */
    protected function made_in_moodle(string $text, \context $context, bool $inquiz = true): int {
        global $DB;
        $top = question_get_top_category($context->id, true);
        $category = $DB->get_record('question_categories', ['contextid' => $context->id, 'name' => 'Team']);
        if (!$category) {
            $category = (object) ['name' => 'Team', 'contextid' => $context->id, 'info' => '', 'infoformat' => FORMAT_HTML,
                'parent' => $top->id, 'sortorder' => 999, 'stamp' => make_unique_id_code()];
            $category->id = $DB->insert_record('question_categories', $category);
        }
        $html = fn(string $t): array => ['text' => $t, 'format' => FORMAT_HTML];
        $question = \question_bank::get_qtype('truefalse')->save_question((object) ['qtype' => 'truefalse'], (object) [
            'category' => $category->id . ',' . $context->id, 'name' => $text, 'questiontext' => $html($text),
            'generalfeedback' => $html(''), 'defaultmark' => 1, 'penalty' => 1, 'correctanswer' => 1,
            'feedbacktrue' => $html(''), 'feedbackfalse' => $html(''), 'showstandardinstruction' => 0, 'hint' => [],
        ]);
        if ($inquiz) {
            quiz_add_quiz_question($question->id, $DB->get_record('quiz', ['id' => $this->quiz->id]), 0, 1);
        }
        return (int) $question->id;
    }

    /**
     * A shared bank outside the quiz: the course until 4.6, a question bank module from 5.0.
     *
     * @return \context
     */
    protected function shared_bank(): \context {
        if (\core_component::get_component_directory('mod_qbank')) {
            $bank = $this->getDataGenerator()->create_module('qbank', ['course' => $this->course->id]);
            return \context_module::instance($bank->cmid);
        }
        return \context_course::instance($this->course->id);
    }

    /**
     * A description put in the quiz.
     *
     * @param string $text
     * @return int The question id.
     */
    protected function description(string $text): int {
        global $DB;
        $top = question_get_top_category($this->context->id, true);
        $category = (object) ['name' => $text, 'contextid' => $this->context->id, 'info' => '', 'infoformat' => FORMAT_HTML,
            'parent' => $top->id, 'sortorder' => 999, 'stamp' => make_unique_id_code()];
        $category->id = $DB->insert_record('question_categories', $category);
        $question = \question_bank::get_qtype('description')->save_question((object) ['qtype' => 'description'], (object) [
            'category' => $category->id . ',' . $this->context->id, 'name' => $text, 'defaultmark' => 0, 'hint' => [],
            'questiontext' => ['text' => $text, 'format' => FORMAT_HTML],
            'generalfeedback' => ['text' => '', 'format' => FORMAT_HTML],
        ]);
        quiz_add_quiz_question($question->id, $DB->get_record('quiz', ['id' => $this->quiz->id]));
        return (int) $question->id;
    }

    /**
     * Shows the quiz to the students, as the team would.
     */
    protected function show(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        set_coursemodule_visible($this->quiz->cmid, 1);
        rebuild_course_cache($this->course->id, true);
    }

    /**
     * A question of the MiwLab, as it comes in the list of operations.
     *
     * @param string $key
     * @param string|null $text
     * @return array
     */
    protected function question(string $key, ?string $text = null): array {
        return ['key' => $key, 'type' => 'truefalse', 'name' => 'Question ' . $key,
            'text' => $text ?? '<p>Statement ' . $key . '</p>', 'feedback' => '', 'known' => [], 'answers' => [
                ['text' => 'true', 'correct' => true, 'feedback' => '', 'match' => ''],
                ['text' => 'false', 'correct' => false, 'feedback' => '', 'match' => ''],
            ]];
    }

    /**
     * The list of operations with only the questions of the quiz.
     *
     * @param array $items
     * @param int $draw
     * @param int|null $cmid
     * @return string
     */
    protected function ops(array $items, int $draw = 0, ?int $cmid = null): string {
        return json_encode(['questions' => [['cmid' => $cmid ?? (int) $this->quiz->cmid, 'draw' => $draw, 'items' => $items]]]);
    }

    /**
     * The preview of these questions.
     *
     * @param array $items
     * @param int $draw
     * @return array
     */
    protected function preview(array $items, int $draw = 0): array {
        return updater::preview($this->course, $this->ops($items, $draw), '', null);
    }

    /**
     * The item of the quiz in a preview.
     *
     * @param array $preview
     * @return array|null
     */
    protected function item(array $preview): ?array {
        foreach ($preview['items'] as $item) {
            if ($item['id'] === 'q:' . $this->quiz->cmid) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Queues and runs a preview, as the task would.
     *
     * @param array $preview
     * @return \stdClass The record, with the results of the items decoded in `results`.
     */
    protected function apply(array $preview): \stdClass {
        global $DB;
        $record = updater::queue(updater::open_preview($preview['previewid']), false, 180);
        $DB->set_field('local_miwlab_restores', 'status', restorer::STATUS_RUNNING, ['id' => $record->id]);
        update_runner::run($DB->get_record('local_miwlab_restores', ['id' => $record->id]));
        $record = $DB->get_record('local_miwlab_restores', ['id' => $record->id]);
        $record->results = [];
        foreach ((json_decode((string) $record->updateresult, true)['items'] ?? []) as $item) {
            $record->results[$item['id']] = $item;
        }
        return $record;
    }

    /**
     * Previews and applies, which has to finish.
     *
     * @param array $items
     * @param int $draw
     * @return \stdClass
     */
    protected function send(array $items, int $draw = 0): \stdClass {
        $record = $this->apply($this->preview($items, $draw));
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        return $record;
    }

    /**
     * The structure of the quiz: each slot with the question or the draw, and the totals.
     *
     * @return array
     */
    protected function structure(): array {
        global $DB;
        $slots = [];
        foreach ($DB->get_records('quiz_slots', ['quizid' => $this->quiz->id], 'slot') as $slot) {
            $where = ['component' => 'mod_quiz', 'questionarea' => 'slot', 'itemid' => $slot->id,
                'usingcontextid' => $this->context->id];
            $entry = $DB->get_field('question_references', 'questionbankentryid', $where);
            $set = $DB->get_field('question_set_references', 'filtercondition', $where);
            $slots[] = ['entry' => (int) $entry, 'set' => (string) $set, 'maxmark' => (float) $slot->maxmark];
        }
        $quiz = $DB->get_record('quiz', ['id' => $this->quiz->id]);
        return ['slots' => $slots, 'grade' => (float) $quiz->grade, 'sumgrades' => (float) $quiz->sumgrades];
    }

    /**
     * The entry of a question.
     *
     * @param int $questionid
     * @return int
     */
    protected function entry_of(int $questionid): int {
        global $DB;
        return (int) $DB->get_field('question_versions', 'questionbankentryid', ['questionid' => $questionid]);
    }

    /**
     * An attempt of a student (or a preview of the team).
     *
     * @param int $preview
     */
    protected function attempt(int $preview = 0): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $usage = $DB->insert_record('question_usages', (object) ['contextid' => $this->context->id, 'component' => 'mod_quiz',
            'preferredbehaviour' => 'deferredfeedback']);
        $DB->insert_record('quiz_attempts', (object) ['quiz' => $this->quiz->id, 'userid' => $user->id, 'attempt' => 1,
            'uniqueid' => $usage, 'layout' => '1,0', 'currentpage' => 0, 'preview' => $preview, 'state' => 'inprogress',
            'timestart' => time(), 'timefinish' => 0, 'timemodified' => time()]);
    }

    public function test_get_info_announces_the_questions(): void {
        $this->assertContains('updatequizquestions', get_info::execute()['features']);
    }

    public function test_without_a_draw_the_questions_are_added_to_the_ones_there(): void {
        $team = $this->entry_of($this->made_in_moodle('Made here', $this->shared_bank()));
        $before = $this->structure();
        $preview = $this->preview([$this->question('a'), $this->question('b')]);
        $item = $this->item($preview);
        $this->assertSame('update', $item['action']);
        $this->assertSame('questions', $item['field']);
        $this->assertSame(2, $item['questions']['add']);
        $this->assertSame(1, $item['questions']['slotsbefore']);
        $this->assertSame(3, $item['questions']['slotsafter']);
        $this->assertSame($before, $this->structure(), 'the preview changes nothing');

        $record = $this->apply($preview);
        $this->assertSame('updated', $record->results['q:' . $this->quiz->cmid]['status']);
        $after = $this->structure();
        $this->assertSame($team, $after['slots'][0]['entry']);
        $this->assertCount(3, $after['slots']);
        $this->assertEquals(3, $after['sumgrades']);
        $this->assertSame($before['grade'], $after['grade']);
    }

    public function test_each_question_goes_once(): void {
        global $DB;
        $this->send([$this->question('a')]);
        $after = $this->structure();
        $count = $DB->count_records('question');
        $this->assertNull($this->item($this->preview([$this->question('a')])));
        // Edited in the MiwLab after it was sent: it is not rewritten. Deleted there: it stays.
        $this->assertNull($this->item($this->preview([$this->question('a', '<p>Edited</p>')])));
        $record = $this->send([$this->question('b')]);
        $this->assertSame('updated', $record->results['q:' . $this->quiz->cmid]['status']);
        $this->assertSame($after['slots'][0], $this->structure()['slots'][0]);
        $this->assertSame($count + 1, $DB->count_records('question'));
        $this->assertTrue($DB->record_exists_select(
            'question',
            $DB->sql_compare_text('questiontext') . ' = :text',
            ['text' => '<p>Statement a</p>']
        ));
    }

    public function test_a_question_taken_out_in_moodle_does_not_come_back(): void {
        global $DB;
        $this->send([$this->question('a')]);
        // Even erased from the bank by the team: the plugin remembers it went.
        foreach ($DB->get_records('question_bank_entries', ['idnumber' => 'miwlab:q:a']) as $entry) {
            $DB->set_field('question_bank_entries', 'idnumber', null, ['id' => $entry->id]);
        }
        foreach ($DB->get_records('quiz_slots', ['quizid' => $this->quiz->id]) as $slot) {
            $DB->delete_records('question_references', ['itemid' => $slot->id, 'usingcontextid' => $this->context->id]);
            $DB->delete_records('quiz_slots', ['id' => $slot->id]);
        }
        $this->assertNull($this->item($this->preview([$this->question('a', '<p>Other text</p>')])));
    }

    public function test_a_copy_of_the_quiz_has_the_stamp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->send([$this->question('a')]);
        $cm = get_coursemodule_from_id('quiz', $this->quiz->cmid);
        $actions = '\core_courseformat\local\cmactions';
        if (class_exists($actions) && method_exists($actions, 'duplicate')) {
            $copy = \core_courseformat\formatactions::cm($this->course)->duplicate($cm->id);
        } else {
            $copy = duplicate_module($this->course, $cm);
        }
        $state = get_question_state::execute(
            (int) $this->course->id,
            $this->ops([$this->question('a', '<p>Edited</p>')], 0, (int) $copy->id)
        );
        $this->assertSame('none', $state['quizzes'][0]['action']);
    }

    public function test_questions_that_came_in_a_package_without_stamp_are_recognised(): void {
        global $DB;
        $first = $this->made_in_moodle('<p>Statement a</p>', $this->context);
        $second = $this->made_in_moodle('<p>The text it had before</p>', $this->context);
        $DB->set_field('question', 'stamp', 'miwlab_q_40002_1700000000', ['id' => $second]);
        $edited = $this->question('b', '<p>The text it has now</p>');
        $edited['known'] = ['<p>The text it had before</p>'];
        $this->assertNull($this->item($this->preview([$this->question('a'), $edited])));
        $this->assertGreaterThan(0, $first);
    }

    public function test_the_same_text_with_another_answer_is_another_question(): void {
        $this->made_in_moodle('<p>Statement a</p>', $this->context);
        $mine = $this->question('a');
        $mine['answers'][0]['correct'] = false;
        $mine['answers'][1]['correct'] = true;
        $this->assertSame(1, $this->item($this->preview([$mine]))['questions']['add']);
    }

    public function test_one_question_of_moodle_is_recognised_only_once(): void {
        $this->made_in_moodle('<p>The same</p>', $this->context);
        $item = $this->item($this->preview([$this->question('a', '<p>The same</p>'), $this->question('b', '<p>The same</p>')]));
        $this->assertSame(1, $item['questions']['add']);
        $this->assertSame(1, $item['questions']['delivered']);
    }

    public function test_a_quiz_with_attempts_is_not_touched(): void {
        $this->made_in_moodle('Made here', $this->context);
        $this->attempt();
        $before = $this->structure();
        $preview = $this->preview([$this->question('a')]);
        $item = $this->item($preview);
        $this->assertSame('skip', $item['action']);
        $this->assertSame('attempts', $item['reason']);
        $this->assertSame(1, $item['people']);
        $record = $this->apply($preview);
        $this->assertSame('skipped', $record->results['q:' . $this->quiz->cmid]['status']);
        $this->assertSame($before, $this->structure());
    }

    public function test_a_preview_of_the_team_is_not_an_attempt(): void {
        $this->attempt(1);
        $item = $this->item($this->preview([$this->question('a')]));
        $this->assertSame('update', $item['action']);
        $this->assertContains('previewsdeleted', $item['warnings']);
    }

    public function test_an_attempt_between_the_preview_and_the_application_keeps_the_quiz_out(): void {
        $preview = $this->preview([$this->question('a')]);
        $this->attempt();
        $before = $this->structure();
        $record = $this->apply($preview);
        $this->assertSame('skipped', $record->results['q:' . $this->quiz->cmid]['status']);
        $this->assertSame('attempts', $record->results['q:' . $this->quiz->cmid]['reason']);
        $this->assertSame($before, $this->structure());
    }

    public function test_an_attempt_during_the_application_undoes_everything(): void {
        global $DB;
        $count = $DB->count_records('question');
        $preview = $this->preview([$this->question('a')]);
        update_runner::$stephook = function (string $step): void {
            if ($step === 'questions:written') {
                $this->attempt();
            }
        };
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FAILED, (int) $record->status);
        $this->assertSame(1, (int) $record->rolledback);
        $this->assertSame([], $this->structure()['slots']);
        $this->assertSame($count, $DB->count_records('question'));
    }

    public function test_a_failure_undoes_the_questions_and_the_rest_of_the_update(): void {
        global $DB;
        $this->made_in_moodle('Made here', $this->context);
        $name = $DB->get_field('quiz', 'name', ['id' => $this->quiz->id]);
        $before = $this->structure();
        $count = $DB->count_records('question');
        $preview = updater::preview($this->course, json_encode([
            'activities' => [['cmid' => (int) $this->quiz->cmid, 'name' => 'Another name']],
            'questions' => [['cmid' => (int) $this->quiz->cmid, 'draw' => 2,
                'items' => [$this->question('a'), $this->question('b'), $this->question('c')]]],
        ]), '', null);
        update_runner::$stephook = function (string $step): void {
            if ($step === 'commit') {
                throw new \moodle_exception('error:updatefailed', 'local_miwlab');
            }
        };
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FAILED, (int) $record->status);
        $this->assertSame(1, (int) $record->rolledback, (string) $record->errormessage);
        $this->assertSame($before, $this->structure());
        $this->assertSame($name, $DB->get_field('quiz', 'name', ['id' => $this->quiz->id]));
        $this->assertSame($count, $DB->count_records('question'));
        $this->assertSame(0, $DB->count_records('local_miwlab_questions'));
    }

    public function test_a_quiz_that_students_can_start_is_left_out(): void {
        $this->show();
        $before = $this->structure();
        foreach ([0, 1] as $draw) {
            $item = $this->item($this->preview([$this->question('a')], $draw));
            $this->assertSame('skip', $item['action']);
            $this->assertSame('canstart', $item['reason']);
        }
        $this->assertSame($before, $this->structure());
    }

    public function test_a_quiz_not_open_yet_closed_or_in_a_hidden_course_is_written(): void {
        global $DB;
        $this->show();
        $now = time();
        $where = ['id' => $this->quiz->id];
        $cases = [
            'not open yet' => ['timeopen' => $now + HOURSECS, 'timeclose' => 0],
            'closed' => ['timeopen' => 0, 'timeclose' => $now - HOURSECS],
        ];
        foreach ($cases as $name => $window) {
            $DB->update_record('quiz', (object) ($where + $window));
            $this->assertSame('update', $this->item($this->preview([$this->question('a')]))['action'], $name);
        }
        $DB->update_record('quiz', (object) ($where + ['timeopen' => 0, 'timeclose' => 0]));
        $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
        $this->assertSame('update', $this->item($this->preview([$this->question('a')]))['action'], 'a hidden course');
    }

    public function test_an_override_that_opens_it_for_somebody_keeps_it_out(): void {
        global $DB;
        $this->show();
        $DB->set_field('quiz', 'timeopen', time() + DAYSECS, ['id' => $this->quiz->id]);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('quiz_overrides', (object) ['quiz' => $this->quiz->id, 'userid' => $user->id,
            'timeopen' => time() - HOURSECS]);
        $this->assertSame('canstart', $this->item($this->preview([$this->question('a')]))['reason']);
    }

    public function test_one_about_to_open_or_just_closed_is_taken_as_open(): void {
        global $DB;
        $this->show();
        $DB->set_field('quiz', 'timeopen', time() + 10, ['id' => $this->quiz->id]);
        $this->assertSame('canstart', $this->item($this->preview([$this->question('a')]))['reason']);
        $DB->update_record('quiz', (object) ['id' => $this->quiz->id, 'timeopen' => 0, 'timeclose' => time() - 10]);
        $this->assertSame('canstart', $this->item($this->preview([$this->question('a')]))['reason']);
    }

    public function test_a_quiz_opened_during_the_application_undoes_everything(): void {
        global $DB;
        $before = $this->structure();
        $count = $DB->count_records('question');
        $preview = $this->preview([$this->question('a')]);
        update_runner::$stephook = function (string $step) use ($DB): void {
            if ($step === 'questions:written') {
                $DB->set_field('course_modules', 'visible', 1, ['id' => $this->quiz->cmid]);
            }
        };
        $this->assertSame(restorer::STATUS_FAILED, (int) $this->apply($preview)->status);
        $this->assertSame($before, $this->structure());
        $this->assertSame($count, $DB->count_records('question'));
    }

    public function test_a_quiz_changed_after_the_preview_is_left_out(): void {
        $preview = $this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2);
        $this->made_in_moodle('Put in after the preview', $this->context);
        $before = $this->structure();
        $record = $this->apply($preview);
        if ((int) $record->status === restorer::STATUS_FINISHED) {
            $this->assertSame('questionschanged', $record->results['q:' . $this->quiz->cmid]['reason']);
        } else {
            $this->assertSame('error:coursechanged', $record->errorcode);
        }
        $this->assertSame($before, $this->structure());
    }

    public function test_with_a_draw_all_of_them_are_one_set(): void {
        global $DB;
        $team = $this->entry_of($this->made_in_moodle('Made here', $this->context));
        $this->made_in_moodle('Only in the bank', $this->context, false);
        $questions = $DB->count_records('question');
        $preview = $this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2);
        $details = $this->item($preview)['questions'];
        $this->assertSame(3, $details['add']);
        $this->assertSame(1, $details['joined']);
        $this->assertSame(4, $details['pool']);
        $this->assertSame(2, $details['draw']);
        $this->assertSame(1, $details['slotsbefore']);
        $this->assertSame(2, $details['slotsafter']);

        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $after = $this->structure();
        $this->assertCount(2, $after['slots']);
        $this->assertSame([0, 0], array_column($after['slots'], 'entry'));
        $this->assertSame($after['slots'][0]['set'], $after['slots'][1]['set']);
        $this->assertEquals(10, $after['grade']);
        $this->assertEquals(2, $after['sumgrades']);
        // The set is exactly the ones of the MiwLab and the one that was in the quiz, not the one that was only in the bank.
        $category = (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $team]);
        $this->assertSame(4, $DB->count_records('question_bank_entries', ['questioncategoryid' => $category]));
        $this->assertSame($questions + 3, $DB->count_records('question'), 'nothing is deleted from the bank');

        // Again: nothing to do. A new question joins the set and the slots stay.
        $all = [$this->question('a'), $this->question('b'), $this->question('c')];
        $this->assertNull($this->item($this->preview($all, 2)));
        $this->send(array_merge($all, [$this->question('d')]), 2);
        $this->assertSame($after['slots'], $this->structure()['slots']);
        $this->assertSame(5, $DB->count_records('question_bank_entries', ['questioncategoryid' => $category]));

        // Moodle itself draws from that set.
        $structure = \mod_quiz\question\bank\qbank_helper::get_question_structure($this->quiz->id, $this->context);
        foreach ($structure as $slot) {
            // Up to 5.2 a draw has the type "random"; from 5.3 on it has no type and is told as random.
            $this->assertTrue($slot->qtype === 'random' || ($slot->qtype === null && !empty($slot->random)));
            $this->assertEquals($category, $slot->category);
        }
    }

    public function test_with_a_draw_a_new_quantity_changes_only_the_slots(): void {
        $all = [$this->question('a'), $this->question('b'), $this->question('c')];
        $this->send($all, 2);
        $item = $this->item($this->preview($all, 1));
        $this->assertSame(0, $item['questions']['add']);
        $this->assertSame(1, $item['questions']['slotsafter']);
        $this->send($all, 1);
        $this->assertCount(1, $this->structure()['slots']);
    }

    public function test_with_a_draw_a_question_of_a_shared_bank_is_copied_into_the_set(): void {
        global $DB;
        $bank = $this->shared_bank();
        $original = $this->made_in_moodle('<p>Shared <img src="@@PLUGINFILE@@/pic.png" alt=""></p>', $bank);
        get_file_storage()->create_file_from_string(['contextid' => $bank->id, 'component' => 'question',
            'filearea' => 'questiontext', 'itemid' => $original, 'filepath' => '/', 'filename' => 'pic.png'], 'the picture');
        $other = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        quiz_add_quiz_question($original, $DB->get_record('quiz', ['id' => $other->id]), 0, 1);
        $DB->set_field('question', 'name', 'Shared picture', ['id' => $original]);
        $before = $DB->get_record('question', ['id' => $original]);
        $entry = $DB->get_record('question_bank_entries', ['id' => $this->entry_of($original)]);
        $count = $DB->count_records('question');

        $preview = $this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2);
        $details = $this->item($preview)['questions'];
        $this->assertSame('update', $this->item($preview)['action']);
        $this->assertSame(1, $details['copied']);
        $this->assertSame(4, $details['pool']);
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);

        // The original is as it was: the question, its place in the bank, its file and the other quiz that uses it.
        $this->assertEquals($before, $DB->get_record('question', ['id' => $original]));
        $this->assertEquals($entry, $DB->get_record('question_bank_entries', ['id' => $entry->id]));
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($bank->id, 'question', 'questiontext', $original, '/', 'pic.png'));
        $this->assertTrue($DB->record_exists(
            'question_references',
            ['usingcontextid' => \context_module::instance($other->cmid)->id, 'questionbankentryid' => $entry->id]
        ));
        // The copy is in the set, in the bank of this quiz, with its file and its answers.
        $this->assertSame($count + 4, $DB->count_records('question'));
        $copy = $DB->get_record_sql(
            "SELECT q.id, qc.idnumber
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
               JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
              WHERE qc.contextid = :context AND q.name = :name",
            ['context' => $this->context->id, 'name' => $before->name],
            MUST_EXIST
        );
        $this->assertStringStartsWith(quiz_questions::SET_CATEGORY, $copy->idnumber);
        $file = $fs->get_file($this->context->id, 'question', 'questiontext', $copy->id, '/', 'pic.png');
        $this->assertSame('the picture', $file->get_content());
        $this->assertEquals(1, \question_bank::load_question($copy->id)->rightanswer);
        $this->assertSame([0, 0], array_column($this->structure()['slots'], 'entry'));
        // Again: nothing to copy and nothing to do.
        $this->assertNull($this->item($this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2)));
    }

    public function test_with_a_draw_a_question_that_another_quiz_uses_is_copied(): void {
        global $DB;
        $question = $this->made_in_moodle('Used twice', $this->context);
        $other = $this->getDataGenerator()->create_module('quiz', ['course' => $this->course->id]);
        quiz_add_quiz_question($question, $DB->get_record('quiz', ['id' => $other->id]), 0, 1);
        $category = $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $this->entry_of($question)]);
        $preview = $this->preview([$this->question('a'), $this->question('b')], 2);
        $this->assertSame(1, $this->item($preview)['questions']['copied']);
        $this->apply($preview);
        $where = ['id' => $this->entry_of($question)];
        $this->assertSame($category, $DB->get_field('question_bank_entries', 'questioncategoryid', $where));
    }

    public function test_without_a_draw_nothing_is_copied(): void {
        $this->made_in_moodle('Shared', $this->shared_bank());
        $this->assertSame(0, $this->item($this->preview([$this->question('a')]))['questions']['copied']);
    }

    public function test_with_a_draw_a_description_stays_fixed_and_the_rest_joins(): void {
        $top = $this->entry_of($this->description('Read this'));
        $this->made_in_moodle('Made here', $this->context);
        $end = $this->entry_of($this->description('The end'));
        $all = [$this->question('a'), $this->question('b'), $this->question('c')];
        $preview = $this->preview($all, 2);
        $details = $this->item($preview)['questions'];
        $this->assertSame(2, $details['kept']);
        $this->assertSame(1, $details['joined']);
        $this->assertSame(4, $details['pool']);
        $this->assertSame(4, $details['slotsafter']);
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $after = $this->structure();
        $this->assertSame([$top, 0, 0, $end], array_column($after['slots'], 'entry'), 'each description keeps its place');
        $this->assertEquals(2, $after['sumgrades']);
        $this->assertNull($this->item($this->preview($all, 2)), 'again: nothing to do');
        $this->send($all, 1);
        $this->assertSame([$top, 0, $end], array_column($this->structure()['slots'], 'entry'));
    }

    public function test_with_a_draw_a_draw_made_in_moodle_keeps_the_quiz_out(): void {
        global $DB;
        $bank = $this->shared_bank();
        $question = $this->made_in_moodle('In the shared bank', $bank, false);
        $category = (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $this->entry_of($question)]);
        quiz_questions_writer::add_random_slots($DB->get_record('quiz', ['id' => $this->quiz->id]), $category, 1);
        $item = $this->item($this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2));
        $this->assertSame('moodledraw', $item['reason']);
        // Without a draw in the MiwLab they are only added, and the draw made in Moodle stays.
        $this->send([$this->question('a')]);
        $this->assertCount(2, $this->structure()['slots']);
        $this->assertNotSame('', $this->structure()['slots'][0]['set']);
    }

    public function test_with_a_draw_a_pinned_version_keeps_the_quiz_out(): void {
        global $DB;
        $this->made_in_moodle('Pinned', $this->context);
        $DB->set_field('question_references', 'version', 1, ['usingcontextid' => $this->context->id]);
        $item = $this->item($this->preview([$this->question('a'), $this->question('b'), $this->question('c')], 2));
        $this->assertSame('notdrawable', $item['reason']);
    }

    public function test_a_set_smaller_than_the_draw_keeps_the_quiz_out(): void {
        $this->assertSame('toofewquestions', $this->item($this->preview([$this->question('a')], 2))['reason']);
    }

    public function test_the_images_of_a_question_are_files_of_the_question(): void {
        global $DB, $USER;
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZTZkAAAAASUVORK5CYII=');
        $name = 'miwlab-' . hash('sha256', $png) . '.png';
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/', 'filename' => $name], $png);
        $img = '<img src="@@PLUGINFILE@@/' . $name . '" alt="figure" />';
        $multi = $this->question('m', '<p>Look ' . $img . '</p>');
        $multi['type'] = 'multichoice';
        $multi['feedback'] = '<p>Because ' . $img . '</p>';
        $multi['answers'] = [
            ['text' => '<p>' . $img . '</p>', 'correct' => true, 'feedback' => '<p>Yes ' . $img . '</p>', 'match' => ''],
            ['text' => '<p>C</p>', 'correct' => false, 'feedback' => '', 'match' => ''],
        ];
        $pair = $this->question('p');
        $pair['type'] = 'match';
        $pair['answers'] = [
            ['text' => '<p>One ' . $img . '</p>', 'correct' => true, 'feedback' => '', 'match' => '1'],
            ['text' => '<p>Two</p>', 'correct' => true, 'feedback' => '', 'match' => '2'],
        ];
        $list = json_decode($this->ops([$multi, $pair]), true);
        $list['questions'][0]['files'] = [['draftitemid' => $draft, 'filename' => $name, 'sha256' => hash('sha256', $png),
            'size' => strlen($png)]];
        $preview = updater::preview($this->course, json_encode($list), '', null);
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $areas = [];
        $files = $DB->get_records_select(
            'files',
            'contextid = :context AND filename = :name',
            ['context' => $this->context->id, 'name' => $name]
        );
        foreach ($files as $file) {
            $areas[] = $file->component . '/' . $file->filearea;
            $this->assertSame($png, get_file_storage()->get_file_by_id($file->id)->get_content());
        }
        sort($areas);
        $expected = ['qtype_match/subquestion', 'question/answer', 'question/answerfeedback', 'question/generalfeedback',
            'question/questiontext'];
        $this->assertSame($expected, $areas);
        $text = (string) $DB->get_field('question', 'questiontext', ['qtype' => 'multichoice']);
        $this->assertStringContainsString('@@PLUGINFILE@@/' . $name, $text);
    }

    public function test_a_jpeg_with_exif_in_the_container_is_a_file_of_the_question(): void {
        global $DB, $USER;
        if (class_exists('\\core_files\\redactor\\manager')) {
            set_config('file_redactor_exifremoverenabled', 1);
        }
        $image = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($image);
        $plain = ob_get_clean();
        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00";
        $jpeg = substr($plain, 0, 2) . "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\x00\x00" . $tiff . substr($plain, 2);
        $name = 'miwlab-' . hash('sha256', $jpeg) . '.jpg';
        // As webservice/upload.php writes it (from a path, the removal of EXIF of the site applied), in the container.
        $path = make_request_directory() . '/' . $name . update_page_files::CONTAINER;
        file_put_contents($path, update_page_files::HEADER . $jpeg);
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname(['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draft, 'filepath' => '/',
            'filename' => $name . update_page_files::CONTAINER], $path);
        $question = $this->question('j', '<p>Look <img src="@@PLUGINFILE@@/' . $name . '" alt="f" /></p>');
        $list = json_decode($this->ops([$question]), true);
        $list['questions'][0]['files'] = [['draftitemid' => $draft, 'filename' => $name, 'sha256' => hash('sha256', $jpeg),
            'size' => strlen($jpeg)]];
        $preview = updater::preview($this->course, json_encode($list), '', null);
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $file = $DB->get_record('files', ['contextid' => $this->context->id, 'filename' => $name, 'filearea' => 'questiontext']);
        $this->assertNotFalse($file);
        $this->assertSame('image/jpeg', $file->mimetype);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring(get_file_storage()->get_file_by_id($file->id)->get_content())[2]);
    }

    public function test_an_embedded_image_is_never_taken_in_silence(): void {
        $bad = $this->question('a', '<p><img src="data:image/png;base64,AAAA" alt=""></p>');
        $this->expectException(\moodle_exception::class);
        $this->preview([$bad]);
    }

    public function test_a_question_that_cites_an_image_without_the_file_is_refused(): void {
        $name = 'miwlab-' . str_repeat('a', 64) . '.png';
        $bad = $this->question('a', '<p>Look <img src="@@PLUGINFILE@@/' . $name . '" alt=""></p>');
        $this->expectException(\moodle_exception::class);
        $this->preview([$bad]);
    }

    public function test_a_slot_put_in_during_the_writing_is_never_taken_out(): void {
        global $DB;
        $this->made_in_moodle('Made here', $this->context);
        $all = [$this->question('a'), $this->question('b'), $this->question('c')];
        $cm = get_coursemodule_from_id('quiz', $this->quiz->cmid);
        $op = question_list::parse(json_decode($this->ops($all, 2), true)['questions'], [$cm->id => $cm])[0];
        $decision = quiz_questions::decide(get_fast_modinfo($this->course->id)->get_cm($cm->id), $op);
        $this->assertSame('update', $decision->action);
        // The team puts another one in between the decision and the writing.
        $this->made_in_moodle('Put in during the writing', $this->context);
        $transaction = $DB->start_delegated_transaction();
        try {
            quiz_questions_writer::apply($decision, 2);
            $this->fail('the slot of the team was taken out');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:coursechanged', $e->errorcode);
        } finally {
            $DB->force_transaction_rollback();
        }
        $this->assertCount(2, $this->structure()['slots']);
    }

    public function test_the_four_types_are_created_as_moodle_reads_them(): void {
        global $DB;
        $multi = $this->question('m', '<p>Choose <script>alert(1)</script>one</p>');
        $multi['type'] = 'multichoice';
        $multi['answers'] = [
            ['text' => '<p>A &amp; B</p>', 'correct' => true, 'feedback' => '<p>Yes</p>', 'match' => ''],
            ['text' => '<p>C</p>', 'correct' => false, 'feedback' => '', 'match' => ''],
        ];
        $short = $this->question('s');
        $short['type'] = 'shortanswer';
        $short['answers'] = [['text' => 'R&D <3', 'correct' => true, 'feedback' => '', 'match' => '']];
        $match = $this->question('p');
        $match['type'] = 'match';
        $match['answers'] = [
            ['text' => '<p>One</p>', 'correct' => true, 'feedback' => '', 'match' => '1'],
            ['text' => '<p>Two</p>', 'correct' => true, 'feedback' => '', 'match' => '2'],
        ];
        $this->send([$multi, $this->question('t'), $short, $match]);
        $loaded = [];
        foreach ($DB->get_records('question_bank_entries') as $entry) {
            $id = $DB->get_field('question_versions', 'questionid', ['questionbankentryid' => $entry->id]);
            $loaded[(string) $entry->idnumber] = \question_bank::load_question($id);
        }
        $this->assertCount(2, $loaded['miwlab:q:m']->answers);
        $this->assertStringNotContainsString('script', $loaded['miwlab:q:m']->questiontext);
        $this->assertEquals(1, $loaded['miwlab:q:t']->rightanswer);
        $this->assertSame('R&D <3', reset($loaded['miwlab:q:s']->answers)->answer);
        $this->assertCount(2, $loaded['miwlab:q:p']->stems);
    }

    public function test_the_name_and_the_pair_never_carry_markup(): void {
        global $DB;
        foreach (['<svg onload=alert(1)>', '&lt;svg onload=alert(1)&gt;', '<p>&lt;svg onload=alert(1)&gt;</p>'] as $n => $bad) {
            $question = $this->question('x' . $n, '<p>&lt;svg onload=alert(1)&gt; ' . $n . '</p>');
            $question['name'] = $bad;
            $question['type'] = 'match';
            $question['answers'] = [
                ['text' => '<p>One</p>', 'correct' => true, 'feedback' => '', 'match' => 'a ' . $bad],
                ['text' => '<p>Two</p>', 'correct' => true, 'feedback' => '', 'match' => 'x < y'],
            ];
            $this->send([$question]);
        }
        $names = $DB->get_fieldset_select('question', 'name', "qtype = 'match'");
        $pairs = $DB->get_fieldset_select('qtype_match_subquestions', 'answertext', '1 = 1');
        $this->assertCount(3, $names);
        foreach (array_merge($names, $pairs) as $kept) {
            $this->assertDoesNotMatchRegularExpression('~<[a-z/!?]~i', $kept);
        }
        $this->assertContains('x < y', $pairs);
    }

    public function test_a_short_answer_never_carries_markup(): void {
        global $DB;
        $bad = ['<script>alert(1)</script>', '<svg onload=alert(1)>', '<!-- x -->', '</p>'];
        foreach ($bad as $n => $answer) {
            $question = $this->question('c' . $n, '<p>Short ' . $n . '</p>');
            $question['type'] = 'shortanswer';
            $question['answers'] = [['text' => $answer, 'correct' => true, 'feedback' => '', 'match' => '']];
            $this->send([$question]);
        }
        $question = $this->question('cm', '<p>Short maths</p>');
        $question['type'] = 'shortanswer';
        $question['answers'] = [['text' => 'x < y', 'correct' => true, 'feedback' => '', 'match' => '']];
        $this->send([$question]);
        $ids = $DB->get_fieldset_select('question', 'id', "qtype = 'shortanswer'");
        [$in, $params] = $DB->get_in_or_equal($ids);
        $kept = $DB->get_fieldset_select('question_answers', 'answer', "question $in", $params);
        $this->assertCount(count($bad) + 1, $kept);
        foreach ($kept as $answer) {
            $this->assertDoesNotMatchRegularExpression('~<[a-z/!?]~i', $answer);
        }
        $this->assertContains('x < y', $kept);
    }

    public function test_a_question_of_another_bank_that_is_not_in_the_quiz_is_not_recognised(): void {
        global $DB;
        $bank = $this->shared_bank();
        $question = $this->made_in_moodle('<p>Statement a</p>', $bank, false);
        $category = (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $this->entry_of($question)]);
        quiz_questions_writer::add_random_slots($DB->get_record('quiz', ['id' => $this->quiz->id]), $category, 1);
        $item = $this->item($this->preview([$this->question('a')]));
        $this->assertSame(0, $item['questions']['delivered']);
        $this->assertSame(1, $item['questions']['add']);
    }

    public function test_a_draw_of_the_team_is_not_a_set_of_the_miwlab_by_likeness(): void {
        global $DB;
        $question = $this->made_in_moodle('<p>Statement a</p>', $this->context, false);
        $category = (int) $DB->get_field('question_bank_entries', 'questioncategoryid', ['id' => $this->entry_of($question)]);
        quiz_questions_writer::add_random_slots($DB->get_record('quiz', ['id' => $this->quiz->id]), $category, 1);
        $item = $this->item($this->preview([$this->question('a'), $this->question('b')], 1));
        $this->assertSame('moodledraw', $item['reason']);
    }

    public function test_what_was_shown_covers_the_layout_that_a_draw_takes_out(): void {
        global $DB;
        $this->made_in_moodle('Made here', $this->context);
        $this->made_in_moodle('Made here too', $this->context);
        $cm = get_coursemodule_from_id('quiz', $this->quiz->cmid);
        $op = question_list::parse(json_decode($this->ops([$this->question('a')], 1), true)['questions'], [$cm->id => $cm])[0];
        $state = function () use ($cm, $op): string {
            return quiz_questions::decide(get_fast_modinfo($this->course->id)->get_cm($cm->id), $op)->state;
        };
        $shown = $state();
        $DB->set_field('quiz_slots', 'requireprevious', 1, ['quizid' => $this->quiz->id, 'slot' => 2]);
        $this->assertNotSame($shown, $required = $state());
        $DB->set_field('quiz_slots', 'page', 7, ['quizid' => $this->quiz->id, 'slot' => 2]);
        $this->assertNotSame($required, $paged = $state());
        $DB->insert_record('quiz_sections', (object) ['quizid' => $this->quiz->id, 'firstslot' => 2, 'heading' => 'Part',
            'shufflequestions' => 0]);
        $this->assertNotSame($paged, $state());
    }

    public function test_recognising_does_not_cost_one_query_for_each_pair(): void {
        global $DB;
        for ($i = 0; $i < 12; $i++) {
            $this->made_in_moodle('<p>The same</p>', $this->context, false);
        }
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $item = $this->question('k' . $i, '<p>The same</p>');
            $item['answers'][0]['correct'] = false;
            $item['answers'][1]['correct'] = true;
            $items[] = $item;
        }
        $reads = function (array $asked) use ($DB): int {
            $before = $DB->perf_get_reads();
            $this->preview($asked);
            return $DB->perf_get_reads() - $before;
        };
        $this->preview($items); // What Moodle keeps in caches is read here.
        $one = $reads([$items[0]]);
        // Twelve questions against twelve entries cost what one costs: each version is read once.
        $this->assertLessThan(12, $reads($items) - $one);
    }

    public function test_the_list_is_refused_whole_when_it_is_not_valid(): void {
        $other = $this->getDataGenerator()->create_course();
        $foreign = $this->getDataGenerator()->create_module('quiz', ['course' => $other->id]);
        $badkey = $this->question('a');
        $badkey['key'] = 'a b';
        $norightanswer = $this->question('a');
        $norightanswer['type'] = 'multichoice';
        $norightanswer['answers'][0]['correct'] = false;
        $unknown = $this->question('a');
        $unknown['delete'] = true;
        $cases = [
            'another course' => [$this->ops([$this->question('a')], 0, (int) $foreign->cmid), 'error:foreignid'],
            'a bad key' => [$this->ops([$badkey]), 'error:invalidoperations'],
            'no right answer' => [$this->ops([$norightanswer]), 'error:invalidoperations'],
            'an unknown field' => [$this->ops([$unknown]), 'error:invalidoperations'],
            'a key repeated' => [$this->ops([$this->question('a'), $this->question('a')]), 'error:invalidoperations'],
            'a negative draw' => [$this->ops([$this->question('a')], -1), 'error:invalidoperations'],
        ];
        foreach ($cases as $name => [$json, $code]) {
            try {
                updater::preview($this->course, $json, '', null);
                $this->fail($name . ' was accepted');
            } catch (\moodle_exception $e) {
                $this->assertSame($code, $e->errorcode, $name);
            }
        }
    }

    public function test_an_activity_that_is_not_a_quiz_is_left_out(): void {
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $preview = updater::preview($this->course, $this->ops([$this->question('a')], 0, (int) $page->cmid), '', null);
        $this->assertSame('unknowntype', $preview['items'][0]['reason']);
    }

    public function test_without_the_permission_in_the_quiz_it_is_left_out(): void {
        $manager = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        $coursecontext = \context_course::instance($this->course->id);
        foreach (updater::CAPABILITIES as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $coursecontext->id, true);
        }
        role_assign($roleid, $manager->id, $coursecontext->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($manager);
        $this->assertSame('nopermission', $this->item($this->preview([$this->question('a')]))['reason']);
    }

    public function test_the_state_is_read_without_changing_anything(): void {
        global $DB;
        $count = $DB->count_records('question_categories') + $DB->count_records('local_miwlab_questions');
        $state = get_question_state::execute((int) $this->course->id, $this->ops([$this->question('a')]));
        $this->assertSame('update', $state['quizzes'][0]['action']);
        $this->assertSame(1, $state['quizzes'][0]['questions']['add']);
        $this->assertSame($count, $DB->count_records('question_categories') + $DB->count_records('local_miwlab_questions'));
    }

    public function test_the_state_needs_the_permission_in_the_course(): void {
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'student'));
        $this->expectException(\moodle_exception::class);
        get_question_state::execute((int) $this->course->id, $this->ops([$this->question('a')]));
    }
}
