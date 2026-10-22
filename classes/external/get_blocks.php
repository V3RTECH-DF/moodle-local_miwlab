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
 * The blocks installed on the site, with their names and whether each is enabled, without depending on a course.
 *
 * The MiwLab adds a third-party block to the courses it sends (the progress bar of its light gamification) only when the
 * site has it installed and enabled; a block that is not installed is skipped by the restore. Only plugin names: no person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_blocks extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns every block installed on the site, in the order of the names, with whether it is enabled.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        // The same capability as the module types and the course formats: all three say only what is installed, by name.
        require_capability('local/miwlab:viewmoduletypes', $context);
        $plugins = \core_plugin_manager::instance()->get_plugins_of_type('block');
        $blocks = [];
        // The table of the installed blocks (Manage blocks): the name and whether it is enabled ("visible"). A block present on
        // disk but not installed yet is not in it, and is left out: it cannot be added to a course.
        foreach ($DB->get_records('block', null, 'name', 'id, name, visible') as $record) {
            $name = (string) $record->name;
            if (!isset($plugins[$name])) {
                continue;
            }
            $blocks[] = [
                'name' => $name,
                'title' => (string) get_string('pluginname', 'block_' . $name),
                'visible' => (bool) $record->visible,
                'standard' => (bool) $plugins[$name]->is_standard(),
            ];
        }
        return ['blocks' => $blocks];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'blocks' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_PLUGIN, 'Block name, as in block_<name>'),
                'title' => new external_value(PARAM_TEXT, 'Name of the block in the language of the account'),
                'visible' => new external_value(PARAM_BOOL, 'Enabled in Manage blocks: it can be added to a course'),
                'standard' => new external_value(PARAM_BOOL, 'Comes with Moodle; false for an add-on'),
            ])),
        ]);
    }
}
