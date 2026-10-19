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
 * Consolidated results of an anonymous feedback, as Moodle consolidates them; open answers only when asked.
 *
 * A feedback that is not anonymous is refused.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_feedback_analysis extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'feedbackid' => new external_value(PARAM_INT, 'Feedback id (instance)'),
            'includetext' => new external_value(PARAM_BOOL, 'Whether the open answers (text) leave', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * Returns the consolidated results.
     *
     * @param int $feedbackid
     * @param bool $includetext
     * @return array
     */
    public static function execute(int $feedbackid, bool $includetext = false): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['feedbackid' => $feedbackid, 'includetext' => $includetext]
        );
        $feedback = $DB->get_record('feedback', ['id' => $params['feedbackid']]);
        if (!$feedback) {
            throw new \moodle_exception('error:feedbacknotfound', 'local_miwlab');
        }
        $course = self::course_for_reports((int) $feedback->course);
        $cm = get_fast_modinfo($course)->instances['feedback'][$feedback->id] ?? null;
        if (!$cm || !empty($cm->deletioninprogress)) {
            throw new \moodle_exception('error:feedbacknotfound', 'local_miwlab');
        }
        return \local_miwlab\local\feedback_report::analysis($feedback, $cm, $course, (bool) $params['includetext']);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'feedbackid' => new external_value(PARAM_INT, 'Feedback id'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'name' => new external_value(PARAM_TEXT, 'Feedback name'),
            'anonymous' => new external_value(PARAM_BOOL, 'Always true: a feedback that is not anonymous is refused'),
            'completedcount' => new external_value(PARAM_INT, 'Responses submitted'),
            'itemscount' => new external_value(PARAM_INT, 'Items that take an answer'),
            'textincluded' => new external_value(PARAM_BOOL, 'Whether the open answers were asked for'),
            'itemsdata' => new external_multiple_structure(new external_single_structure([
                'item' => get_course_feedbacks::item_structure(),
                'data' => new external_multiple_structure(new external_value(
                    PARAM_RAW,
                    'As mod_feedback_get_analysis: JSON per option (answertext, answercount, quotient; value in the rated);'
                    . ' the text of each open answer; for numeric, one JSON with answercount and average'
                )),
                'withheld' => new external_value(
                    PARAM_BOOL,
                    'Data withheld: open answers not asked for, or a type with nothing to count'
                ),
                'answercount' => new external_value(
                    PARAM_INT,
                    'For open and numeric questions, how many non-empty answers (also when the text is withheld); 0 for the others'
                ),
            ])),
        ]);
    }
}
