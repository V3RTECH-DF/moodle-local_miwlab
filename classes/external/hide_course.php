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
 * Hides a course (1.5.0), as "Hide" in the course list does: for the old course, once a new one takes its place. Nothing else
 * changes, and showing it again is done in Moodle.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hide_course extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course to hide'),
        ]);
    }

    /**
     * Hides the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        replacer::require_capabilities($context, $course, replacer::HIDE_CAPABILITIES);
        $wasvisible = replacer::hide($course);
        return ['courseid' => (int) $course->id, 'visible' => false, 'changed' => $wasvisible];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'visible' => new external_value(PARAM_BOOL, 'Always false: the course is hidden'),
            'changed' => new external_value(PARAM_BOOL, 'The course was visible and is hidden now'),
        ]);
    }
}
