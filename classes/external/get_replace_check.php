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
 * Before replacing a course (1.5.0): the course, how many people are enrolled and how many have progress there (counts only),
 * and whether the account can replace it now.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_replace_check extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course that would be replaced'),
        ]);
    }

    /**
     * Returns the course and the counts.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        require_capability('local/miwlab:replacecourse', $context);
        $people = replacer::people($course->id);
        $missing = replacer::missing_capabilities($context);
        $inprogress = replacer::in_progress($course->id);
        $unknown = replacer::unknown_modules($course->id);
        $category = \core_course_category::get($course->category, IGNORE_MISSING, true);
        $state = replacer::course_state($course->id);
        return [
            'courseid' => (int) $course->id,
            'fullname' => $course->fullname,
            'shortname' => $course->shortname,
            'categoryid' => (int) $course->category,
            'categoryname' => $category ? $category->get_formatted_name() : '',
            'visible' => (bool) $course->visible,
            'format' => (string) $course->format,
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            'enrolled' => $people['enrolled'],
            'withprogress' => $people['withprogress'],
            'missingcapabilities' => $missing,
            'inprogress' => $inprogress,
            'canreplace' => !$missing && !$inprogress && !$unknown && $people['withprogress'] === 0,
            'unknownmodules' => $unknown,
            'canhide' => !replacer::missing_capabilities($context, replacer::HIDE_CAPABILITIES),
            'idnumber' => (string) $course->idnumber,
            'signature' => $state['signature'],
            'contentmodified' => $state['contentmodified'],
        ];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'fullname' => new external_value(PARAM_RAW, 'Full name of the course'),
            'shortname' => new external_value(PARAM_RAW, 'Short name of the course'),
            'categoryid' => new external_value(PARAM_INT, 'Category of the course'),
            'categoryname' => new external_value(PARAM_RAW, 'Name of the category'),
            'visible' => new external_value(PARAM_BOOL, 'Whether the course is visible'),
            'format' => new external_value(PARAM_PLUGIN, 'Course format'),
            'courseurl' => new external_value(PARAM_URL, 'Address of the course'),
            'enrolled' => new external_value(PARAM_INT, 'People enrolled (active or not), the MiwLab account aside'),
            'withprogress' => new external_value(
                PARAM_INT,
                'People with progress: an activity or the course completed, a grade, a submission, an attempt, an answer, a post, '
                    . 'a note'
            ),
            'missingcapabilities' => new external_multiple_structure(
                new external_value(PARAM_CAPABILITY, 'A capability the account lacks in the course to replace it')
            ),
            'inprogress' => new external_value(PARAM_BOOL, 'A replacement of this course is queued or running'),
            'canreplace' => new external_value(PARAM_BOOL, 'Nobody has progress, nothing is missing and nothing is running'),
            'canhide' => new external_value(PARAM_BOOL, 'The account can hide the course (local_miwlab_hide_course)'),
            'unknownmodules' => new external_multiple_structure(
                new external_value(PARAM_PLUGIN, 'Module of an activity of the course whose progress the plugin cannot read')
            ),
            'idnumber' => new external_value(PARAM_RAW, 'ID number of the course (a MiwLab marker, when the MiwLab sent it)'),
            'signature' => new external_value(PARAM_ALPHANUM, 'State of the content now (as local_miwlab_get_course_state)'),
            'contentmodified' => new external_value(PARAM_INT, 'Latest edit of the content (as local_miwlab_get_course_state)'),
        ]);
    }
}
