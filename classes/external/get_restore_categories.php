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

use local_miwlab\local\restorer;

/**
 * Categories where the account can restore a MiwLab package as a new course.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_restore_categories extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the categories where every capability of the restore is held.
     *
     * @return array
     */
    public static function execute(): array {
        self::validate_context(\context_system::instance());
        $categories = [];
        $all = \core_course_category::make_categories_list('local/miwlab:restorecourse');
        foreach ($all as $id => $path) {
            $context = \context_coursecat::instance($id);
            if (restorer::missing_capabilities($context)) {
                continue;
            }
            $category = \core_course_category::get($id, MUST_EXIST, true);
            $categories[] = [
                'id' => (int) $id,
                'name' => $category->get_formatted_name(),
                'path' => $path,
                'parent' => (int) $category->parent,
                'idnumber' => (string) $category->idnumber,
                'visible' => (bool) $category->visible,
            ];
        }
        return ['categories' => $categories];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'categories' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Category id'),
                'name' => new external_value(PARAM_TEXT, 'Name of the category'),
                'path' => new external_value(PARAM_TEXT, 'Names from the top category, separated by " / "'),
                'parent' => new external_value(PARAM_INT, 'Parent category id, 0 at the top'),
                'idnumber' => new external_value(PARAM_RAW, 'Category ID number'),
                'visible' => new external_value(PARAM_BOOL, 'Whether the category is visible'),
            ])),
        ]);
    }
}
