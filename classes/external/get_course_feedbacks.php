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
 * Feedback activities of a course, whether each is anonymous, and their questions (no answer).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_feedbacks extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
        ]);
    }

    /**
     * Returns the feedbacks.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = self::course_for_reports($params['courseid']);
        return ['courseid' => (int) $course->id, 'feedbacks' => \local_miwlab\local\feedback_report::list($course)];
    }

    /**
     * The structure of an item, shared with local_miwlab_get_feedback_analysis.
     *
     * @return external_single_structure
     */
    public static function item_structure(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Item id'),
            'typ' => new external_value(PARAM_ALPHANUMEXT, 'Item type (multichoice, multichoicerated, textarea, label...)'),
            'name' => new external_value(PARAM_RAW, 'Question text, as stored'),
            'label' => new external_value(PARAM_RAW, 'Label, seen only by who edits (carries the MiwLab marker)'),
            'presentation' => new external_value(PARAM_RAW, 'Options, as stored'),
            'position' => new external_value(PARAM_INT, 'Position in the feedback'),
            'hasvalue' => new external_value(PARAM_BOOL, 'Whether the item takes an answer'),
            'required' => new external_value(PARAM_BOOL, 'Whether the answer is required'),
        ]);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'feedbacks' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Feedback id (instance)'),
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'sectionnumber' => new external_value(PARAM_INT, 'Section number'),
                'name' => new external_value(PARAM_TEXT, 'Feedback name'),
                'anonymous' => new external_value(
                    PARAM_BOOL,
                    'Whether the feedback is anonymous; only anonymous ones are analysed'
                ),
                'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible'),
                'items' => new external_multiple_structure(self::item_structure()),
            ])),
        ]);
    }
}
