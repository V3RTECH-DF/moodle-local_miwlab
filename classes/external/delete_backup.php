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
 * Deletes a content backup once the MiwLab downloaded it (it is deleted after a day anyway).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class delete_backup extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return get_backup_status::execute_parameters();
    }

    /**
     * Deletes it, for whoever can back up the content of that course.
     *
     * @param int $backupid
     * @param string $secret
     * @return array
     */
    public static function execute(int $backupid, string $secret): array {
        $params = self::validate_parameters(self::execute_parameters(), ['backupid' => $backupid, 'secret' => $secret]);
        exporter::delete(get_backup_status::record($params['backupid'], $params['secret']));
        return ['deleted' => true];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure(['deleted' => new external_value(PARAM_BOOL, 'Always true')]);
    }
}
