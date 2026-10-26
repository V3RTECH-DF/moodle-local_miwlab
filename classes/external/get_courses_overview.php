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
 * The numbers of the courses of some categories, in batch: counts only, no person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_courses_overview extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Category id'),
                'Categories whose courses are read (up to ' . \local_miwlab\local\courses_overview::MAX_CATEGORIES . ')'
            ),
            'includesubcategories' => new external_value(
                PARAM_BOOL,
                'Also read the courses of the categories below them',
                VALUE_DEFAULT,
                true
            ),
            'limitfrom' => new external_value(PARAM_INT, 'Courses to skip, by course id order', VALUE_DEFAULT, 0),
            'limitnum' => new external_value(
                PARAM_INT,
                'Most courses in the answer (' . \local_miwlab\local\courses_overview::MAX_PER_CALL . ' at most)',
                VALUE_DEFAULT,
                \local_miwlab\local\courses_overview::MAX_PER_CALL
            ),
        ]);
    }

    /**
     * Returns the courses of the categories with their counts.
     *
     * @param array $categoryids
     * @param bool $includesubcategories
     * @param int $limitfrom
     * @param int $limitnum
     * @return array
     */
    public static function execute(
        array $categoryids,
        bool $includesubcategories = true,
        int $limitfrom = 0,
        int $limitnum = \local_miwlab\local\courses_overview::MAX_PER_CALL
    ): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryids' => $categoryids,
            'includesubcategories' => $includesubcategories,
            'limitfrom' => $limitfrom,
            'limitnum' => $limitnum,
        ]);
        $requested = array_values(array_unique(array_map('intval', $params['categoryids'])));
        if (!$requested || count($requested) > \local_miwlab\local\courses_overview::MAX_CATEGORIES) {
            throw new \invalid_parameter_exception('categoryids');
        }
        // The capability is checked in the context of each category asked, before anything is read: the subcategories are
        // inside the context of their parent, so the right given there reaches them.
        foreach ($requested as $categoryid) {
            if (!$DB->record_exists('course_categories', ['id' => $categoryid])) {
                throw new \moodle_exception('error:categorynotfound', 'local_miwlab');
            }
            $context = self::validate_category_context($categoryid);
            require_capability('local/miwlab:viewreports', $context);
        }
        $limitfrom = max(0, (int) $params['limitfrom']);
        $limitnum = min(\local_miwlab\local\courses_overview::MAX_PER_CALL, max(1, (int) $params['limitnum']));
        $ids = \local_miwlab\local\courses_overview::category_ids($requested, (bool) $params['includesubcategories']);
        // A right can be prohibited in a subcategory: each category the expansion added is checked on its own, and the ones
        // that fail stay out (their courses are neither listed nor counted); the answer says how many were left out.
        $allowed = [];
        $leftout = 0;
        foreach ($ids as $id) {
            if (in_array($id, $requested, true)) {
                $allowed[] = $id;
                continue;
            }
            $context = \context_coursecat::instance($id, IGNORE_MISSING);
            if ($context && has_capability('local/miwlab:viewreports', $context)) {
                $allowed[] = $id;
            } else {
                $leftout++;
            }
        }
        [$total, $courses] = \local_miwlab\local\courses_overview::build($allowed, $limitfrom, $limitnum);
        $next = $limitfrom + count($courses);
        return [
            'total' => $total,
            'next' => $next < $total ? $next : -1,
            'courses' => $courses,
            'categoriesleftout' => $leftout,
            'timegenerated' => time(),
        ];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'total' => new external_value(PARAM_INT, 'Courses in the categories asked'),
            'next' => new external_value(PARAM_INT, 'The limitfrom of the next page, or -1 when this was the last'),
            'courses' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course id'),
                'categoryid' => new external_value(PARAM_INT, 'Category id'),
                'fullname' => new external_value(PARAM_RAW, 'Full name'),
                'shortname' => new external_value(PARAM_RAW, 'Short name'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
                'students' => new external_value(
                    PARAM_INT,
                    'Active enrolled users with a role of the student archetype in the course or above it'
                ),
                'completionenabled' => new external_value(PARAM_BOOL, 'Whether completion tracking is enabled in the course'),
                'trackedactivities' => new external_value(PARAM_INT, 'Activities with completion tracking'),
                'measures' => new external_value(
                    PARAM_BOOL,
                    'Whether the course measures completion: tracking on and at least one tracked activity or completion criteria'
                ),
                'completedby' => new external_value(
                    PARAM_ALPHA,
                    'What "completed" counts: course (completion criteria), activities (every tracked activity) or none'
                ),
                'completed' => new external_value(PARAM_INT, 'Students who completed (0 when the course does not measure)'),
            ])),
            'categoriesleftout' => new external_value(
                PARAM_INT,
                'Subcategories left out because the account has no local/miwlab:viewreports in them'
            ),
            'timegenerated' => new external_value(PARAM_INT, 'When the counts were made'),
        ]);
    }
}
