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
 * Courses whose ID number carries a MiwLab marker: the marker itself, or the marker followed by ":" and more.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class find_courses extends base {
    /** Most courses returned. */
    const LIMIT = 100;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'marker' => new external_value(
                PARAM_RAW_TRIMMED,
                'Marker, or its beginning: miwlab:<course> also finds miwlab:<course>:v2 and miwlab:<course>:v2:<id>'
            ),
        ]);
    }

    /**
     * Returns the courses with the marker that the account can read the reports of.
     *
     * @param string $marker
     * @return array
     */
    public static function execute(string $marker): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['marker' => $marker]);
        if (!\local_miwlab\local\restorer::valid_marker($params['marker'])) {
            throw new \moodle_exception('error:invalidmarker', 'local_miwlab');
        }
        self::validate_context(\context_system::instance());
        $like = $DB->sql_like('idnumber', ':prefix', true, true, false, '|');
        $records = $DB->get_records_select(
            'course',
            "idnumber = :marker OR $like",
            ['marker' => $params['marker'], 'prefix' => $DB->sql_like_escape($params['marker'] . ':', '|') . '%'],
            'id',
            'id, category, fullname, shortname, idnumber, visible, timecreated',
            0,
            self::LIMIT
        );
        $courses = [];
        foreach ($records as $course) {
            $context = \context_course::instance($course->id);
            if (!has_capability('local/miwlab:viewreports', $context)) {
                continue;
            }
            $courses[] = [
                'id' => (int) $course->id,
                'categoryid' => (int) $course->category,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'idnumber' => $course->idnumber,
                'visible' => (bool) $course->visible,
                'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'timecreated' => (int) $course->timecreated,
            ];
        }
        return ['courses' => $courses];
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
                'idnumber' => new external_value(PARAM_RAW, 'Course ID number (the marker)'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                'url' => new external_value(PARAM_URL, 'Address of the course'),
                'timecreated' => new external_value(PARAM_INT, 'When the course was created'),
            ])),
        ]);
    }
}
