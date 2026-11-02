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
 * The competencies linked to a course and to its activities (1.10.0, issue 252). Reads only.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_competencies extends base {
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
     * Returns the links of the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = self::course_for_reports($params['courseid']);
        require_capability('local/miwlab:viewcompetencies', \context_course::instance($course->id));
        return ['competencies' => \local_miwlab\local\competencies::course_links($course)];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'competencies' => new external_multiple_structure(new external_single_structure([
                'competencyid' => new external_value(PARAM_INT, 'Competency id'),
                'idnumber' => new external_value(PARAM_RAW, 'ID number of the competency'),
                'shortname' => new external_value(PARAM_RAW, 'Name of the competency'),
                'frameworkid' => new external_value(PARAM_INT, 'Framework id'),
                'frameworkidnumber' => new external_value(PARAM_RAW, 'ID number of the framework'),
                'ruleoutcome' => new external_value(
                    PARAM_ALPHA,
                    'What happens in the course: none, evidence, recommend or complete'
                ),
                'activities' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'ruleoutcome' => new external_value(PARAM_ALPHA, 'What happens when it is completed'),
                ])),
            ])),
        ]);
    }
}
