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

use local_miwlab\local\replacer;

/**
 * The state of the content of a course (1.5.0): a signature of its structure and the latest edit, to know whether it changed in
 * Moodle since the MiwLab last knew it (before replacing it, and in the realignment). No person: what people do in the course
 * is not part of it.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_state extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
        ]);
    }

    /**
     * Returns the state, once the account is checked against local/miwlab:viewreports in the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = self::course_for_reports($params['courseid']);
        return ['courseid' => (int) $course->id] + replacer::course_state((int) $course->id);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'signature' => new external_value(PARAM_ALPHANUM, 'Signature of the structure of the content (sha1)'),
            'contentmodified' => new external_value(
                PARAM_INT,
                'Latest edit of a section or an activity, or activity added (Unix time)'
            ),
            'activities' => new external_value(PARAM_INT, 'Activities and resources in the course'),
            'sections' => new external_value(PARAM_INT, 'Sections in the course'),
        ]);
    }
}
