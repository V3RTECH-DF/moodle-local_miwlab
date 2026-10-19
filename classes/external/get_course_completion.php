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
 * Consolidated activity completion of a course, by section and by activity: counts only, no person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_completion extends base {
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
     * Returns the counts.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = self::course_for_reports($params['courseid']);
        return \local_miwlab\local\completion_report::build($course);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'completionenabled' => new external_value(PARAM_BOOL, 'Whether completion tracking is enabled in the course'),
            'students' => new external_value(
                PARAM_INT,
                'Active enrolled users with a role of the student archetype: the total of every count below'
            ),
            'coursecompletion' => new external_single_structure([
                'enabled' => new external_value(PARAM_BOOL, 'Whether the course has completion criteria'),
                'completed' => new external_value(PARAM_INT, 'Students who completed the course'),
            ]),
            'sections' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Section id'),
                'number' => new external_value(PARAM_INT, 'Section number, 0 first'),
                'name' => new external_value(PARAM_TEXT, 'Section name'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the section is visible'),
                'activities' => new external_value(PARAM_INT, 'Activities of the section with completion tracking'),
                'completed' => new external_value(PARAM_INT, 'Students who completed all those activities (0 when there are none)'),
            ])),
            'activities' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'sectionid' => new external_value(PARAM_INT, 'Section id'),
                'sectionnumber' => new external_value(PARAM_INT, 'Section number'),
                'modname' => new external_value(PARAM_PLUGIN, 'Activity type'),
                'name' => new external_value(PARAM_TEXT, 'Activity name'),
                'idnumber' => new external_value(PARAM_RAW, 'Activity ID number'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the activity is visible'),
                'completiontype' => new external_value(PARAM_INT, '1 manual, 2 automatic'),
                'completed' => new external_value(PARAM_INT, 'Students with the activity complete (or complete with a pass grade)'),
                'completedfail' => new external_value(PARAM_INT, 'Students with the activity complete but without the pass grade'),
            ]), 'Only activities with completion tracking'),
            'timegenerated' => new external_value(PARAM_INT, 'When the counts were made'),
        ]);
    }
}
