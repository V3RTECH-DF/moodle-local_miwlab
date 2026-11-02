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
 * Writes the stamp of the MiwLab into the ID number of activities of a course that was already in Moodle, never over a value that
 * is there (1.10.0, issue 254).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stamp_activities extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id in Moodle'),
            'activities' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'stamp' => new external_value(PARAM_RAW_TRIMMED, 'miwlab:<course>:a:<activity>'),
            ]), 'The activities to stamp'),
        ]);
    }

    /**
     * Stamps the activities that have no ID number yet.
     *
     * @param int $courseid
     * @param array $activities
     * @return array
     */
    public static function execute(int $courseid, array $activities): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid, 'activities' => $activities]);
        if (count($params['activities']) > \local_miwlab\local\stamps::MAX_ITEMS) {
            throw new \moodle_exception('error:toomanyitems', 'local_miwlab', '', \local_miwlab\local\stamps::MAX_ITEMS);
        }
        $course = $DB->get_record('course', ['id' => $params['courseid']]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        self::validate_category_context((int) $course->category);
        require_capability('local/miwlab:updatecourse', \context_course::instance($course->id));
        $result = \local_miwlab\local\stamps::stamp($course, $params['activities']);
        return $result + ['writtencount' => count($result['written']), 'skippedcount' => count($result['skipped'])];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'written' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'stamp' => new external_value(PARAM_RAW, 'The stamp written'),
            ]), 'The stamps written'),
            'skipped' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'Course module id'),
                'stamp' => new external_value(PARAM_RAW, 'The stamp asked'),
                'reason' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'invalidstamp, notincourse, deleting, same, existing, duplicated or idnumbertaken'
                ),
                'current' => new external_value(PARAM_RAW, 'The ID number the activity has now (empty when none)'),
            ]), 'The ones not written, and why'),
            'writtencount' => new external_value(PARAM_INT, 'How many were written'),
            'skippedcount' => new external_value(PARAM_INT, 'How many were not'),
        ]);
    }
}
