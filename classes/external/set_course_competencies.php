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
 * Links competencies of Moodle to a course and to its activities, with what happens when the activity is completed (1.10.0,
 * issue 252).
 *
 * Never removes or changes a link that exists.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_course_competencies extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'links' => new external_multiple_structure(new external_single_structure([
                'competencyid' => new external_value(PARAM_INT, 'Competency id'),
                'cmids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Course module id'),
                    'The activities that prove it',
                    VALUE_DEFAULT,
                    []
                ),
                'outcome' => new external_value(
                    PARAM_ALPHA,
                    'When the activity is completed: none, evidence, recommend or complete (marks the competency as achieved)',
                    VALUE_DEFAULT,
                    'complete'
                ),
            ])),
        ]);
    }

    /**
     * Links the competencies.
     *
     * @param int $courseid
     * @param array $links
     * @return array
     */
    public static function execute(int $courseid, array $links): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'links' => $links]);
        $course = self::course_for_competencies($params['courseid']);
        return ['links' => \local_miwlab\local\competencies::link_to_course($course, $params['links'])];
    }

    /**
     * The course whose competencies are written, once the account is checked against local/miwlab:managecompetencies in it.
     *
     * @param int $courseid
     * @return \stdClass
     */
    protected static function course_for_competencies(int $courseid): \stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        self::validate_category_context((int) $course->category);
        require_capability('local/miwlab:managecompetencies', \context_course::instance($course->id));
        return $course;
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'links' => new external_multiple_structure(new external_single_structure([
                'competencyid' => new external_value(PARAM_INT, 'Competency id'),
                'courseaction' => new external_value(PARAM_ALPHA, 'added, existing or skipped'),
                'reason' => new external_value(PARAM_ALPHANUMEXT, 'competencynotfound, nopermission or frameworkhidden (skipped)'),
                'completeachieves' => new external_value(
                    PARAM_BOOL,
                    'Completing an activity marks this competency as achieved (the default grade of its scale is a proficient one)'
                ),
                'activities' => new external_multiple_structure(new external_single_structure([
                    'cmid' => new external_value(PARAM_INT, 'Course module id'),
                    'action' => new external_value(PARAM_ALPHA, 'added, existing (left as it was) or skipped'),
                    'ruleoutcome' => new external_value(PARAM_ALPHA, 'What happens when it is completed, as it is now'),
                    'reason' => new external_value(PARAM_ALPHANUMEXT, 'notincourse or deleting (skipped)'),
                ])),
            ])),
        ]);
    }
}
