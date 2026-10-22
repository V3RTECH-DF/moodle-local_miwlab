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
 * The activity and resource types (mod plugins) installed on the site, without depending on a course.
 *
 * For each: the module, its name in the language of the account, whether it is enabled, whether it comes with Moodle
 * (standard) or is an add-on, and its version. The MiwLab offers these types when the course is designed.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_module_types extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns every installed mod plugin.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/miwlab:viewmoduletypes', $context);
        $names = get_module_types_names();
        $modules = [];
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('mod') as $name => $info) {
            $modules[] = [
                'name' => (string) $name,
                'title' => isset($names[$name]) ? (string) $names[$name] : (string) $info->displayname,
                'enabled' => (bool) $info->is_enabled(),
                'standard' => (bool) $info->is_standard(),
                'version' => (int) $info->versiondisk,
            ];
        }
        return ['modules' => $modules];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'modules' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_PLUGIN, 'Module name, as in mod_<name>'),
                'title' => new external_value(PARAM_TEXT, 'Name of the type in the language of the account'),
                'enabled' => new external_value(PARAM_BOOL, 'Enabled on the site (Manage activities)'),
                'standard' => new external_value(PARAM_BOOL, 'Comes with Moodle; false for an add-on'),
                'version' => new external_value(PARAM_INT, 'Version of the module on disk'),
            ])),
        ]);
    }
}
