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
 * Adds the course badges and the progress bar to a course that already exists in Moodle (1.12.0, issue 333).
 *
 * Repeating it adds nothing: a badge the course has by name (awarded or not) and a block already on the page stay as they are.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_gamification extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'badges' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Stable key of the badge (the code of the competency)'),
                'name' => new external_value(
                    PARAM_TEXT,
                    'Name of the badge; the badge the course has by this name is left as it is'
                ),
                'description' => new external_value(PARAM_TEXT, 'Description of the badge'),
                'issuer' => new external_value(PARAM_TEXT, 'Name of the issuer', VALUE_DEFAULT, 'MiwLab'),
                'cmids' => new external_multiple_structure(
                    new external_value(PARAM_INT, 'Course module id'),
                    'The activities whose completion awards it (all of them)'
                ),
                'draftitemid' => new external_value(
                    PARAM_INT,
                    'Draft area with the picture of the badge; 0: no picture',
                    VALUE_DEFAULT,
                    0
                ),
                'filename' => new external_value(PARAM_FILE, 'File name of the picture in the draft area', VALUE_DEFAULT, ''),
            ]), 'The badges to have', VALUE_DEFAULT, []),
            'progressblock' => new external_value(
                PARAM_BOOL,
                'Add the progress bar block to the course page',
                VALUE_DEFAULT,
                false
            ),
        ]);
    }

    /**
     * Applies the gamification.
     *
     * @param int $courseid
     * @param array $badges
     * @param bool $progressblock
     * @return array
     */
    public static function execute(int $courseid, array $badges, bool $progressblock): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'badges' => $badges, 'progressblock' => $progressblock,
        ]);
        $course = $DB->get_record('course', ['id' => $params['courseid']]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        require_capability('local/miwlab:managegamification', $context);
        if ($params['badges']) {
            require_capability('moodle/badges:createbadge', $context);
            require_capability('moodle/badges:configurecriteria', $context);
        }
        return \local_miwlab\local\gamification::apply_to_course($course, $params['badges'], (bool) $params['progressblock']);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'completion' => new external_value(PARAM_ALPHA, 'notneeded, already, enabled or siteoff (completion of the course)'),
            'badges' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_ALPHANUMEXT, 'Key sent'),
                'action' => new external_value(PARAM_ALPHA, 'created, existing or skipped'),
                'reason' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'badgesoff, coursebadgesoff, completionoff or noactivities (skipped)'
                ),
                'badgeid' => new external_value(PARAM_INT, 'Id of the badge; 0 when skipped'),
                'active' => new external_value(PARAM_BOOL, 'The badge is active'),
            ])),
            'block' => new external_single_structure([
                'action' => new external_value(PARAM_ALPHA, 'notasked, added, existing or skipped'),
                'reason' => new external_value(PARAM_ALPHANUMEXT, 'notinstalled or disabled (skipped)'),
            ]),
        ]);
    }
}
