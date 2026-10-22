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
 * The certificate templates of the site, for the Course certificate activity (mod_coursecertificate, of tool_certificate).
 *
 * The MiwLab sends a course with the Course certificate pointing at a template; the template belongs to the site (it is
 * not part of a course backup), so the id is only good in the same site. For each template: its id and name, whether it
 * belongs to the whole site or to a course category (and which), and whether it is shared with the categories below. The
 * plugin and the activity can be missing, installed or disabled: nothing fails, the answer says so. Only names and
 * ids of templates: no person, no issued certificate.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_certificate_templates extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the certificate templates, with where each one belongs.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB;
        $context = \context_system::instance();
        self::validate_context($context);
        // The same capability as the module types, the course formats and the blocks: all of them say only what is installed.
        require_capability('local/miwlab:viewmoduletypes', $context);
        $activity = $DB->get_record('modules', ['name' => 'coursecertificate'], 'id, visible');
        $tool = \core_plugin_manager::instance()->get_plugin_info('tool_certificate');
        $installed = $activity && $tool && $DB->get_manager()->table_exists('tool_certificate_templates');
        $result = [
            'installed' => (bool) $installed,
            'enabled' => $installed && !empty($activity->visible),
            'templates' => [],
        ];
        if (!$installed) {
            return $result;
        }
        $records = $DB->get_records('tool_certificate_templates', null, 'name ASC, id ASC', 'id, name, shared, contextid');
        foreach ($records as $record) {
            $templatecontext = \context::instance_by_id((int) $record->contextid, IGNORE_MISSING);
            if (!$templatecontext) {
                continue;
            }
            $categoryid = 0;
            $categoryname = '';
            if ($templatecontext->contextlevel == CONTEXT_COURSECAT) {
                $category = $DB->get_record('course_categories', ['id' => $templatecontext->instanceid], 'id, name');
                if (!$category) {
                    continue;
                }
                $categoryid = (int) $category->id;
                $categoryname = format_string($category->name, true, ['context' => $templatecontext]);
            } else if ($templatecontext->contextlevel != CONTEXT_SYSTEM) {
                continue;
            }
            $result['templates'][] = [
                'id' => (int) $record->id,
                'name' => format_string($record->name, true, ['context' => $templatecontext]),
                'shared' => !empty($record->shared),
                'categoryid' => $categoryid,
                'categoryname' => $categoryname,
            ];
        }
        return $result;
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'installed' => new external_value(PARAM_BOOL, 'The Course certificate activity and its certificate tool are installed'),
            'enabled' => new external_value(PARAM_BOOL, 'The Course certificate activity is enabled in Manage activities'),
            'templates' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Id of the template'),
                'name' => new external_value(PARAM_TEXT, 'Name of the template'),
                'shared' => new external_value(PARAM_BOOL, 'Shared with the categories below the one it belongs to'),
                'categoryid' => new external_value(PARAM_INT, 'Category the template belongs to; 0 for the whole site'),
                'categoryname' => new external_value(PARAM_TEXT, 'Name of that category; empty for the whole site'),
            ])),
        ]);
    }
}
