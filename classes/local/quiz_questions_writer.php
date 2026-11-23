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
 * Writes what quiz_questions decided (1.15.0, issue 349), with the functions of Moodle: creates the questions with their stamp,
 * adds them to the quiz and, with a draw, makes the one set (copying what is of another use). It deletes no question and
 * changes none of another use. It runs inside the transaction of the
 * update, so a failure anywhere leaves the quiz and the bank as they were.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_questions_writer {
    /** The ID number of the category of the questions only added. */
    const ADDED_CATEGORY = 'miwlab:questions';

    /**
     * Applies the decision of one quiz.
     *
     * @param stdClass $decision quiz_questions::decide(), with action update.
     * @param int $draw How many to draw, 0 none.
     * @param array $images The verified images of the questions, by name (update_page_files::of_questions).
     */
    public static function apply(stdClass $decision, int $draw, array $images = []): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->libdir . '/questionlib.php');
        $bank = $decision->bank;
        $quiz = $bank->quiz;
        self::require_untouched((int) $quiz->id, (int) $quiz->cmid);

        if ($draw > 0) {
            $category = $decision->pool
                ?: self::category($bank->context, quiz_questions::SET_CATEGORY . ':' . bin2hex(random_bytes(4)), 'setcategory');
        } else {
            $category = self::category($bank->context, self::ADDED_CATEGORY, 'questionscategory');
        }
        if ($decision->join) {
            [$sql, $params] = $DB->get_in_or_equal($decision->join);
            $ids = $DB->get_fieldset_select('question_versions', 'questionid', "questionbankentryid $sql", $params);
            question_move_questions_to_category($ids, $category);
        }
        $went = $decision->delivered;
        foreach ($decision->copy as $entryid => $questionid) {
            self::copy_question((int) $questionid, $category, (int) $bank->entries[$entryid]->contextid);
        }
        foreach ($decision->new as $question) {
            $questionid = self::create($question, $category, $bank->context, $images);
            $went[$question['key']] = (int) $DB->get_field(
                'question_versions',
                'questionbankentryid',
                ['questionid' => $questionid],
                MUST_EXIST
            );
            if ($draw === 0) {
                quiz_add_quiz_question($questionid, $quiz, 0, 1);
            }
        }
        if ($draw > 0 && $decision->rebuild) {
            self::require_slots_as_decided($quiz, $bank->slots);
            $before = self::kept_before_the_questions($bank->slots, $decision->kept);
            self::remove_slots($quiz, $decision->kept);
            self::add_random_slots($quiz, $category, $draw);
            self::place_draws($quiz, $decision->kept, $before);
            quiz_repaginate_questions($quiz->id, (int) $quiz->questionsperpage);
        }
        self::remember((int) $quiz->cmid, $went);
        quiz_delete_previews($quiz);
        self::update_sumgrades($quiz);
        // The maximum grade is not touched. The time of the change is what tells the state of the course that it changed.
        $DB->set_field('quiz', 'timemodified', time(), ['id' => $quiz->id]);
    }

    /**
     * Refuses when somebody has an attempt or can start one now.
     *
     * @param int $quizid
     * @param int $cmid
     * @throws moodle_exception error:quizhasattempts or error:quizcanstart
     */
    public static function require_untouched(int $quizid, int $cmid): void {
        self::require_no_attempts($quizid);
        if (quiz_bank::can_be_started($cmid)) {
            throw new moodle_exception('error:quizcanstart', 'local_miwlab');
        }
    }

    /**
     * Refuses when somebody has an attempt.
     *
     * @param int $quizid
     * @throws moodle_exception error:quizhasattempts
     */
    public static function require_no_attempts(int $quizid): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (quiz_has_attempts($quizid)) {
            throw new moodle_exception('error:quizhasattempts', 'local_miwlab');
        }
    }

    /**
     * A category of the bank of the quiz, by its ID number; made when it is not there.
     *
     * @param \context_module $context
     * @param string $idnumber
     * @param string $name The string with its name.
     * @return int
     */
    protected static function category(\context_module $context, string $idnumber, string $name): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $found = $DB->get_field('question_categories', 'id', ['contextid' => $context->id, 'idnumber' => $idnumber]);
        if ($found) {
            return (int) $found;
        }
        $top = question_get_top_category($context->id, true);
        return (int) $DB->insert_record('question_categories', (object) [
            'name' => get_string($name, 'local_miwlab'),
            'contextid' => $context->id,
            'info' => '',
            'infoformat' => FORMAT_HTML,
            'parent' => $top->id,
            'sortorder' => 999,
            'stamp' => make_unique_id_code(),
            'idnumber' => $idnumber,
        ]);
    }

    /**
     * Creates a question in the bank, as the form of its type would.
     *
     * @param array $question One question of question_list::parse().
     * @param int $category
     * @param \context_module $context
     * @param array $images The verified images, by name.
     * @return int The question id.
     */
    protected static function create(array $question, int $category, \context_module $context, array $images): int {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        // A field with images goes as the form of Moodle sends it: the text and a draft area with its files, which
        // save_question moves to the area of that field.
        $html = function (string $text) use ($images): array {
            $field = ['text' => $text, 'format' => FORMAT_HTML];
            $cited = array_intersect_key($images, array_flip(update_classifier::cited_files($text)));
            return $cited ? $field + ['itemid' => self::draft_of($cited)] : $field;
        };
        $type = $question['type'];
        $form = [
            'category' => $category . ',' . $context->id,
            'name' => $question['name'],
            'questiontext' => $html($question['text']),
            'generalfeedback' => $html($question['feedback']),
            'defaultmark' => 1,
            'penalty' => $type === 'truefalse' ? 1 : 0.3333333,
            'idnumber' => question_list::STAMP . $question['key'],
            'hint' => [],
        ];
        $answers = $question['answers'];
        if ($type === 'truefalse') {
            $form += ['correctanswer' => $answers[0]['correct'] ? 1 : 0, 'showstandardinstruction' => 0,
                'feedbacktrue' => $html($answers[0]['feedback']), 'feedbackfalse' => $html($answers[1]['feedback'])];
        } else if ($type === 'match') {
            $form += ['shuffleanswers' => 1, 'subquestions' => [], 'subanswers' => []];
            foreach ($answers as $answer) {
                $form['subquestions'][] = $html($answer['text']);
                $form['subanswers'][] = $answer['match'];
            }
        } else {
            $form += ['answer' => [], 'fraction' => [], 'feedback' => []];
            foreach ($answers as $answer) {
                $form['answer'][] = $type === 'shortanswer' ? $answer['text'] : $html($answer['text']);
                $form['fraction'][] = $answer['correct'] ? '1.0' : '0.0';
                $form['feedback'][] = $html($answer['feedback']);
            }
            if ($type === 'shortanswer') {
                $form['usecase'] = 0;
            } else {
                $form += ['single' => 1, 'shuffleanswers' => 1, 'answernumbering' => 'abc', 'showstandardinstruction' => 0];
            }
        }
        if ($type === 'multichoice' || $type === 'match') {
            $form += [
                'correctfeedback' => $html(get_string('correctfeedbackdefault', 'question')),
                'partiallycorrectfeedback' => $html(get_string('partiallycorrectfeedbackdefault', 'question')),
                'incorrectfeedback' => $html(get_string('incorrectfeedbackdefault', 'question')),
                'shownumcorrect' => 1,
            ];
        }
        $saved = \question_bank::get_qtype($type)->save_question((object) ['qtype' => $type], (object) $form);
        return (int) $saved->id;
    }

    /**
     * Refuses when the quiz has a slot that was not in the decision: it would leave the quiz without joining the set.
     *
     * @param stdClass $quiz
     * @param stdClass[] $decided The slots of the decision.
     * @throws moodle_exception error:coursechanged
     */
    protected static function require_slots_as_decided(stdClass $quiz, array $decided): void {
        global $DB;
        $now = array_map('intval', $DB->get_fieldset_select('quiz_slots', 'id', 'quizid = ?', [$quiz->id]));
        $then = array_map('intval', array_column($decided, 'id'));
        sort($now);
        sort($then);
        if ($now !== $then) {
            throw new moodle_exception('error:coursechanged', 'local_miwlab');
        }
    }

    /**
     * How many of the slots that stay come before the first one that goes out: the draws take that place.
     *
     * @param stdClass[] $slots The slots of the decision, in order.
     * @param int[] $kept
     * @return int
     */
    protected static function kept_before_the_questions(array $slots, array $kept): int {
        $before = 0;
        foreach ($slots as $slot) {
            if (!in_array((int) $slot->id, $kept, true)) {
                break;
            }
            $before++;
        }
        return $before;
    }

    /**
     * A draft area with these images.
     *
     * @param array $images Verified entries of update_page_files.
     * @return int
     */
    protected static function draft_of(array $images): int {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        $itemid = file_get_unused_draft_itemid();
        $context = \context_user::instance($USER->id);
        foreach ($images as $name => $entry) {
            update_page_files::create([
                'contextid' => $context->id, 'component' => 'user', 'filearea' => 'draft', 'itemid' => $itemid,
                'filepath' => '/', 'filename' => $name, 'mimetype' => $entry['mimetype'],
            ], $entry);
        }
        return $itemid;
    }

    /**
     * Takes the slots out of the quiz, but the ones that stay (Descriptions). The questions stay in the bank.
     *
     * @param stdClass $quiz
     * @param int[] $kept
     */
    protected static function remove_slots(stdClass $quiz, array $kept): void {
        global $DB;
        // One section is left: Moodle does not take out the last slot of a section when there are others.
        $sections = $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot', 'id');
        foreach (array_slice(array_keys($sections), 1) as $sectionid) {
            $DB->delete_records('quiz_sections', ['id' => $sectionid]);
        }
        // From the end, so that the number of the next one to go does not change.
        foreach ($DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot DESC', 'id, slot') as $slot) {
            if (!in_array((int) $slot->id, $kept, true)) {
                \mod_quiz\structure::create_for_quiz(self::quiz_object((int) $quiz->id))->remove_slot((int) $slot->slot);
            }
        }
    }

    /**
     * Puts the draws, which Moodle adds at the end, where the questions were, among the Descriptions that stay.
     *
     * @param stdClass $quiz
     * @param int[] $kept
     * @param int $before How many of the kept come before them.
     */
    protected static function place_draws(stdClass $quiz, array $kept, int $before): void {
        global $DB;
        if ($before >= count($kept)) {
            return;
        }
        $slots = array_map('intval', array_keys($DB->get_records('quiz_slots', ['quizid' => $quiz->id], 'slot', 'id, slot')));
        $stay = array_slice($slots, 0, count($kept));
        $draws = array_slice($slots, count($kept));
        $order = array_merge(array_slice($stay, 0, $before), $draws, array_slice($stay, $before));
        // The number of a slot is unique in the quiz: first out of the way, then each one in its place.
        foreach ($order as $position => $id) {
            $DB->set_field('quiz_slots', 'slot', -($position + 1), ['id' => $id]);
        }
        foreach ($order as $position => $id) {
            $DB->set_field('quiz_slots', 'slot', $position + 1, ['id' => $id]);
        }
    }

    /**
     * Copies a question into the set with the export and import of Moodle (XML, which carries the files). The original is
     * only read.
     *
     * @param int $questionid
     * @param int $category
     * @param int $fromcontext The context of the bank the original is in.
     * @throws moodle_exception error:questionnotcopied
     */
    protected static function copy_question(int $questionid, int $category, int $fromcontext): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        $target = $DB->get_record('question_categories', ['id' => $category], '*', MUST_EXIST);
        $context = \context::instance_by_id($target->contextid);
        $course = get_course($context->get_course_context()->instanceid);

        $original = clone(\question_bank::load_question_data($questionid));
        $original->contextid = $fromcontext;
        $export = new \qformat_xml();
        $export->setCourse($course);
        $export->setQuestions([$original]);
        $export->setCattofile(false);
        $export->setContexttofile(false);
        $xml = $export->exportpreprocess() ? $export->exportprocess(false) : '';
        $file = make_request_directory() . '/question.xml';
        file_put_contents($file, $xml);

        $import = new \qformat_xml();
        $import->setCourse($course);
        $import->setCategory($target);
        $import->setFilename($file);
        // A grade Moodle does not know stops the copy: it is never changed into the nearest.
        $import->setMatchgrades('error');
        $import->setCatfromfile(false);
        $import->setContextfromfile(false);
        $import->setStoponerror(true);
        $import->set_display_progress(false);
        // The importer writes its notices on the page, and here there is none.
        ob_start();
        try {
            $done = $import->importpreprocess() && $import->importprocess() && $import->importpostprocess();
        } finally {
            ob_end_clean();
        }
        if (!$done || count($import->questionids) !== 1) {
            throw new moodle_exception('error:questionnotcopied', 'local_miwlab');
        }
    }

    /**
     * Adds draws from a category.
     *
     * @param stdClass $quiz
     * @param int $category
     * @param int $number
     */
    public static function add_random_slots(stdClass $quiz, int $category, int $number): void {
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        if (method_exists('\mod_quiz\structure', 'add_random_questions')) {
            // Moodle 4.3 on.
            $structure = \mod_quiz\structure::create_for_quiz(self::quiz_object((int) $quiz->id));
            $structure->add_random_questions(0, $number, ['filter' => ['category' => [
                'name' => 'category',
                'jointype' => 1,
                'values' => [$category],
                'filteroptions' => ['includesubcategories' => false],
            ]]]);
            return;
        }
        quiz_add_random_questions($quiz, 0, $category, $number, false);
    }

    /**
     * The quiz as Moodle works with it.
     *
     * @param int $quizid
     * @return object mod_quiz\quiz_settings from 4.2, quiz before.
     */
    protected static function quiz_object(int $quizid) {
        if (class_exists('\mod_quiz\quiz_settings')) {
            return \mod_quiz\quiz_settings::create($quizid);
        }
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        return \quiz::create($quizid);
    }

    /**
     * The sum of the marks, again.
     *
     * @param stdClass $quiz
     */
    protected static function update_sumgrades(stdClass $quiz): void {
        if (class_exists('\mod_quiz\grade_calculator')) {
            self::quiz_object((int) $quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            return;
        }
        global $CFG;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        quiz_update_sumgrades($quiz);
    }

    /**
     * Records what went, so that it never goes again, even if the team takes it out of the bank.
     *
     * @param int $cmid
     * @param array $went key => entry.
     */
    protected static function remember(int $cmid, array $went): void {
        global $DB;
        $known = $DB->get_records_menu(quiz_questions::TABLE, ['cmid' => $cmid], '', 'questionkey, entryid');
        $now = time();
        foreach ($went as $key => $entryid) {
            if (!array_key_exists($key, $known)) {
                $DB->insert_record(quiz_questions::TABLE, (object) ['cmid' => $cmid, 'questionkey' => (string) $key,
                    'entryid' => (int) $entryid, 'timecreated' => $now]);
            }
        }
    }
}
