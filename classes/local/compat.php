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

namespace local_miwlab\local;

use moodle_exception;
use stdClass;

/**
 * The few course-editing functions of Moodle whose API changed between 4.1 and 5.3 (1.9.0, issue 204): from 5.2 on the global
 * functions of course/lib.php (moveto_module, course_delete_module, set_section_visible, move_section_to) are deprecated and
 * their place is taken by the format actions. Each method here uses the new API when the running Moodle has it and the old one
 * otherwise, so the plugin runs on every supported version without deprecation notices.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compat {
    /**
     * The format actions class when the running Moodle has it (5.0 on), with the method asked.
     *
     * @param string $kind cm or section.
     * @param string $method
     * @return string|null The class name, or null.
     */
    protected static function actions(string $kind, string $method): ?string {
        $class = $kind === 'cm' ? '\core_courseformat\local\cmactions' : '\core_courseformat\local\sectionactions';
        if (class_exists('\core_courseformat\formatactions') && class_exists($class) && method_exists($class, $method)) {
            return $class;
        }
        return null;
    }

    /**
     * Moves an activity to the place just before another one (in the section of that one).
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $beforecmid
     */
    public static function move_cm_before(int $courseid, int $cmid, int $beforecmid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::actions('cm', 'move_before')) {
            \core_courseformat\formatactions::cm($courseid)->move_before($cmid, $beforecmid);
            return;
        }
        $cm = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
        $before = get_coursemodule_from_id('', $beforecmid, $courseid, false, MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $before->section], '*', MUST_EXIST);
        moveto_module($cm, $section, $before);
    }

    /**
     * Moves an activity to the end of a section.
     *
     * @param int $courseid
     * @param int $cmid
     * @param int $sectionid
     */
    public static function move_cm_to_end(int $courseid, int $cmid, int $sectionid): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::actions('cm', 'move_end_section')) {
            \core_courseformat\formatactions::cm($courseid)->move_end_section($cmid, $sectionid);
            return;
        }
        $cm = get_coursemodule_from_id('', $cmid, $courseid, false, MUST_EXIST);
        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $courseid], '*', MUST_EXIST);
        moveto_module($cm, $section);
    }

    /**
     * Deletes an activity (and what hangs from it: instance, grade item, events, files).
     *
     * @param int $courseid
     * @param int $cmid
     */
    public static function delete_cm(int $courseid, int $cmid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::actions('cm', 'delete')) {
            \core_courseformat\formatactions::cm($courseid)->delete($cmid);
            return;
        }
        course_delete_module($cmid);
    }

    /**
     * Shows or hides a section (and, as Moodle does, the activities in it).
     *
     * @param stdClass $course
     * @param stdClass $section The course_sections record.
     * @param bool $visible
     */
    public static function set_section_visible(stdClass $course, stdClass $section, bool $visible): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        if (self::actions('section', 'set_visibility')) {
            $info = get_fast_modinfo($course->id)->get_section_info_by_id((int) $section->id, MUST_EXIST);
            \core_courseformat\formatactions::section($course->id)->set_visibility($info, $visible);
            return;
        }
        set_section_visible($course->id, (int) $section->section, $visible ? 1 : 0);
    }

    /**
     * Moves a section so that it becomes the one at the position given.
     *
     * @param stdClass $course
     * @param stdClass $section The course_sections record.
     * @param int $position The section number it will have.
     */
    public static function move_section(stdClass $course, stdClass $section, int $position): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        if ((int) $section->section === $position) {
            return;
        }
        if (self::actions('section', 'move_at')) {
            $info = get_fast_modinfo($course->id)->get_section_info_by_id((int) $section->id, MUST_EXIST);
            \core_courseformat\formatactions::section($course->id)->move_at($info, $position);
            return;
        }
        if (!move_section_to($course, (int) $section->section, $position, true)) {
            throw new moodle_exception('error:movesectionfailed', 'local_miwlab');
        }
    }
}
