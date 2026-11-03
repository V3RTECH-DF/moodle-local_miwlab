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
 * The grading scales of the site (and of a course), with their names and items (1.10.0, issue 250), for the MiwLab to offer them
 * where an activity is graded by a scale (the rating of the posts of a forum, for example: "stars").
 *
 * Only what the scale is: id, name, items and who owns it. No grade, no person.
 *
 * 1.10.1: also `scale`, the text of the scale exactly as Moodle stores it (the items trimmed in `items` lose the spaces after the
 * commas, and when a course is restored Moodle reuses the scale of the site only when the text is identical, so
 * "Bad, Good, Great" is not "Bad,Good,Great").
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_scales extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(
                PARAM_INT,
                'A course whose own scales come too (0: only the scales of the site)',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Returns the scales of the site, and the ones of the course asked.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid = 0): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $context = \context_system::instance();
        self::validate_context($context);
        // The same capability as the module types and the blocks: all of them say only what the site has.
        require_capability('local/miwlab:viewmoduletypes', $context);
        $courseids = [0];
        if ($params['courseid'] > 0) {
            // The scales of a course belong to its teachers: reading them needs the right to read the course.
            $course = self::course_for_reports($params['courseid']);
            $courseids[] = (int) $course->id;
        }
        [$in, $inparams] = $DB->get_in_or_equal($courseids);
        $scales = [];
        foreach ($DB->get_records_select('scale', "courseid $in", $inparams, 'courseid ASC, name ASC, id ASC') as $scale) {
            $items = array_values(array_filter(array_map('trim', explode(',', (string) $scale->scale)), 'strlen'));
            $scales[] = [
                'id' => (int) $scale->id,
                'courseid' => (int) $scale->courseid,
                'name' => format_string($scale->name, true, ['context' => $context]),
                'items' => $items,
                'scale' => (string) $scale->scale,
                'itemcount' => count($items),
                'description' => trim(html_to_text(
                    format_text((string) $scale->description, (int) $scale->descriptionformat, ['context' => $context]),
                    0,
                    false
                )),
            ];
        }
        return ['scales' => $scales];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'scales' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Scale id'),
                'courseid' => new external_value(PARAM_INT, 'The course that owns it; 0: the site'),
                'name' => new external_value(PARAM_TEXT, 'Name'),
                'items' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Item'), 'The items, from the lowest'),
                'scale' => new external_value(
                    PARAM_RAW,
                    'The text of the scale exactly as stored (items separated by commas, with the spaces typed), 1.10.1',
                    VALUE_OPTIONAL
                ),
                'itemcount' => new external_value(PARAM_INT, 'How many items'),
                'description' => new external_value(PARAM_TEXT, 'Description, as text'),
            ])),
        ]);
    }
}
