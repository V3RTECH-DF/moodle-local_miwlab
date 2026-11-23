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

use local_miwlab\local\question_list;
use local_miwlab\local\quiz_questions;
use local_miwlab\local\replacer;
use local_miwlab\local\updater;

/**
 * What the questions written in the MiwLab would change in the quizzes of a course (1.15.0, issue 349), for the window of the
 * MiwLab to offer only what is pending: to add, already there, or left out with the reason. It changes nothing. Counts only.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_question_state extends base {
    /** Biggest list accepted, in bytes. */
    const MAX_BYTES = 4194304;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'questions' => new external_value(PARAM_RAW, 'A JSON object with the key questions, as in the list of the safe update'),
        ]);
    }

    /**
     * The state of each quiz.
     *
     * @param int $courseid
     * @param string $questions
     * @return array
     */
    public static function execute(int $courseid, string $questions): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'questions' => $questions]);
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        updater::require_capabilities(\context_course::instance($course->id), $course);
        $data = strlen($params['questions']) <= self::MAX_BYTES ? json_decode($params['questions'], true) : null;
        if (!is_array($data) || array_keys($data) !== ['questions']) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'questions');
        }
        $cms = $DB->get_records('course_modules', ['course' => $course->id], '', 'id');
        $list = question_list::parse($data['questions'], $cms);
        $modinfo = get_fast_modinfo($course->id);
        $quizzes = [];
        foreach ($list as $quiz) {
            $decision = quiz_questions::decide($modinfo->cms[$quiz['cmid']] ?? null, $quiz);
            $quizzes[] = [
                'cmid' => $quiz['cmid'],
                'action' => $decision->action,
                'reason' => $decision->reason,
                'people' => $decision->people,
                'warnings' => $decision->warnings,
                'questions' => $decision->details,
            ];
        }
        return ['courseid' => (int) $course->id, 'quizzes' => $quizzes];
    }

    /**
     * What is told about the questions of one quiz.
     *
     * @return external_single_structure
     */
    public static function details(): external_single_structure {
        return new external_single_structure([
            'add' => new external_value(PARAM_INT, 'Questions of the MiwLab to add'),
            'delivered' => new external_value(PARAM_INT, 'Questions of the MiwLab that already went: never sent again'),
            'joined' => new external_value(PARAM_INT, 'With a draw: questions made in Moodle, in the quiz, that join the set'),
            'copied' => new external_value(PARAM_INT, 'With a draw: copied into the set, the original intact'),
            'kept' => new external_value(PARAM_INT, 'With a draw: Descriptions that stay fixed'),
            'draw' => new external_value(PARAM_INT, 'How many are drawn; 0 without a draw'),
            'pool' => new external_value(PARAM_INT, 'With a draw: the questions in the one set, afterwards'),
            'slotsbefore' => new external_value(PARAM_INT, 'Questions in the quiz now'),
            'slotsafter' => new external_value(PARAM_INT, 'Questions in the quiz afterwards'),
            'marksbefore' => new external_value(PARAM_FLOAT, 'Sum of the marks now'),
            'marksafter' => new external_value(PARAM_FLOAT, 'Sum of the marks afterwards'),
            'grade' => new external_value(PARAM_FLOAT, 'Maximum grade of the quiz, which does not change'),
        ], 'The questions of a quiz (zeros for what is not one)');
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'quizzes' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'The quiz'),
                'action' => new external_value(PARAM_ALPHA, 'none, update or skip'),
                'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why it is left out'),
                'people' => new external_value(PARAM_INT, 'People with an attempt, when that is the reason (a count only)'),
                'warnings' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Does not block')),
                'questions' => self::details(),
            ])),
        ]);
    }
}
