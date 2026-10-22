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

use local_miwlab\local\exporter;

/**
 * Queues the backup of the content of a course, without any data of a person, for the MiwLab to download (1.6.0).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_course extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
        ]);
    }

    /**
     * Checks the capabilities in the course and queues the backup.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = exporter::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        exporter::require_capabilities(\context_course::instance($course->id));
        return exporter::export(exporter::queue((int) $course->id));
    }

    /**
     * Returned structure: the same as local_miwlab_get_backup_status.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $structure = get_backup_status::execute_returns();
        $structure->keys['secret'] = new external_value(
            PARAM_ALPHANUM,
            'Returned only here, once: status, parts and deletion of this backup need it'
        );
        return $structure;
    }
}
