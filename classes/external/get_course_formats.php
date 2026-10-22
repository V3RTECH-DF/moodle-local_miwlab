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
 * The course formats a course of the site can take: the format plugins installed and enabled, in the order and with the
 * names of the "Course format" field of the course settings form, without depending on a course.
 *
 * For each: the format, its name in the language of the account, whether it comes with Moodle (standard) or is an add-on,
 * and whether it is the default format of new courses. A disabled format is left out, as in the course settings form. The
 * MiwLab offers these formats to the organisation, for the courses it sends to Moodle.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_course_formats extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns every enabled course format of the site.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $context = \context_system::instance();
        self::validate_context($context);
        // The same capability as the list of the module types: both say only what is installed on the site, by name.
        require_capability('local/miwlab:viewmoduletypes', $context);
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('format');
        $default = (string) get_config('moodlecourse', 'format');
        $formats = [];
        // The list of the course settings form: installed, present on disk and not disabled, in the order of Manage course formats.
        foreach (get_sorted_course_formats(true) as $name) {
            $formats[] = [
                'name' => (string) $name,
                'title' => (string) get_string('pluginname', 'format_' . $name),
                'standard' => isset($plugins[$name]) && (bool) $plugins[$name]->is_standard(),
                'default' => $name === $default,
            ];
        }
        return ['formats' => $formats];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'formats' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_PLUGIN, 'Format name, as in format_<name> and in the format of a course'),
                'title' => new external_value(PARAM_TEXT, 'Name of the format in the language of the account'),
                'standard' => new external_value(PARAM_BOOL, 'Comes with Moodle; false for an add-on'),
                'default' => new external_value(PARAM_BOOL, 'The default format of new courses on the site'),
            ])),
        ]);
    }
}
