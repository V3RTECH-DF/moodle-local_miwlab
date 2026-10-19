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

defined('MOODLE_INTERNAL') || die();

// Moodle 4.2 moved the external API to the core_external namespace; Moodle 4.1 has it in lib/externallib.php
// under global names. The functions of this plugin use the names below, aliased to whichever exists.
// lib/externallib.php is only loaded on 4.1: from 4.2 on it is deprecated.
if (!class_exists(\core_external\external_api::class)) {
    global $CFG;
    require_once($CFG->libdir . '/externallib.php');
}
foreach (
    [
        'external_api',
        'external_value',
        'external_single_structure',
        'external_multiple_structure',
        'external_function_parameters',
        'external_warnings',
    ] as $localmiwlabname
) {
    if (!class_exists(__NAMESPACE__ . '\\' . $localmiwlabname, false)) {
        $localmiwlabsource = class_exists('core_external\\' . $localmiwlabname) ? 'core_external\\' . $localmiwlabname
            : $localmiwlabname;
        class_alias($localmiwlabsource, __NAMESPACE__ . '\\' . $localmiwlabname);
    }
}
unset($localmiwlabname, $localmiwlabsource);

/**
 * Base of the web service functions of the plugin, on the external API of the running Moodle.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base extends external_api {
    /**
     * Validates the context of a category, so that no course enrolment nor activity access is required.
     *
     * The data is checked against the capabilities of the plugin by each function, in the right context.
     *
     * @param int $categoryid
     * @return \context_coursecat
     */
    protected static function validate_category_context(int $categoryid): \context_coursecat {
        $context = \context_coursecat::instance($categoryid);
        self::validate_context($context);
        return $context;
    }

    /**
     * The course whose consolidated reports are asked, once the account is checked against local/miwlab:viewreports.
     *
     * Only the category context is validated: no enrolment, course visibility or activity access is needed.
     *
     * @param int $courseid
     * @return \stdClass
     */
    protected static function course_for_reports(int $courseid): \stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === SITEID) {
            throw new \moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        self::validate_category_context((int) $course->category);
        require_capability('local/miwlab:viewreports', \context_course::instance($course->id));
        return $course;
    }
}
