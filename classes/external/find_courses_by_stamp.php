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
 * Courses of the site that carry the stamp of the activities of a course of the MiwLab (1.10.0, issue 254): the copies of the
 * course made in Moodle, which lose the marker of the course but keep the ID number of the activities.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_courses_by_stamp extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'miwlabcourse' => new external_value(
                PARAM_RAW_TRIMMED,
                'Identifier of the course in the MiwLab (the <course> of the stamp)'
            ),
            'includestamps' => new external_value(PARAM_BOOL, 'List the stamped activities of each course', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Returns the courses with stamped activities that the account can read the reports of.
     *
     * @param string $miwlabcourse
     * @param bool $includestamps
     * @return array
     */
    public static function execute(string $miwlabcourse, bool $includestamps = true): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['miwlabcourse' => $miwlabcourse, 'includestamps' => $includestamps]
        );
        if (!\local_miwlab\local\stamps::valid_course($params['miwlabcourse'])) {
            throw new \moodle_exception('error:invalidmarker', 'local_miwlab');
        }
        self::validate_context(\context_system::instance());
        return \local_miwlab\local\stamps::find_courses($params['miwlabcourse'], (bool) $params['includestamps']);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'categoryid' => new external_value(PARAM_INT, 'Category id'),
                'fullname' => new external_value(PARAM_RAW, 'Full name'),
                'shortname' => new external_value(PARAM_RAW, 'Short name'),
                'idnumber' => new external_value(PARAM_RAW, 'Course ID number (the marker, when it kept it)'),
                'marked' => new external_value(PARAM_BOOL, 'The course ID number is the marker of this course of the MiwLab'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                'url' => new external_value(PARAM_URL, 'Address of the course'),
                'timecreated' => new external_value(PARAM_INT, 'When the course was created'),
                'stampedcount' => new external_value(PARAM_INT, 'How many activities carry a stamp of this course'),
                'stamps' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'stamp' => new external_value(PARAM_RAW, 'The stamp'),
                ]), 'The stamped activities (empty when not asked)'),
            ])),
            'total' => new external_value(PARAM_INT, 'How many courses the account can read carry the stamp'),
            'truncated' => new external_value(PARAM_BOOL, 'More courses than the list carries'),
        ]);
    }
}
