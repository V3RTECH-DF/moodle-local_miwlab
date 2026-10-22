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
 * The type of each forum of a course (1.6.0, 2026102108): general, qanda (Questions and answers), single (a single simple
 * discussion), eachuser (each person posts one discussion) or blog. The course structure that Moodle's own
 * core_course_get_contents returns does not have it, and the MiwLab needs it to show the right type of the forum and to offer
 * only the completion conditions a person can fulfil in it (in a Questions and answers forum or a single discussion, nobody
 * but who leads opens a discussion). Only the activity and its type: no person, no post.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_forum_types extends base {
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
     * Returns the forums of the course with their types, once the account is checked against local/miwlab:viewreports in the
     * course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = self::course_for_reports($params['courseid']);
        $forums = [];
        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, f.type
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module AND m.name = :modname
               JOIN {forum} f ON f.id = cm.instance
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
           ORDER BY cm.id",
            ['modname' => 'forum', 'courseid' => $course->id]
        );
        foreach ($rows as $row) {
            $forums[] = ['cmid' => (int) $row->cmid, 'type' => (string) $row->type];
        }
        return ['courseid' => (int) $course->id, 'forums' => $forums];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'forums' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'The course module (the id of the activity in the course)'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'general, qanda, single, eachuser or blog'),
            ])),
        ]);
    }
}
