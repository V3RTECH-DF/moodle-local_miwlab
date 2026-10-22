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
 * A part of a finished content backup, in base64, for the request that queued it (its secret): the MiwLab downloads the file
 * this way, part after part, and checks the size and the SHA-1 the status gave. The plugin serves nothing through
 * webservice/pluginfile.php, and the service does not download files: only the backup this request queued leaves Moodle.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_backup_chunk extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'backupid' => new external_value(PARAM_INT, 'Identifier returned by local_miwlab_backup_course'),
            'secret' => new external_value(PARAM_ALPHANUM, 'The secret local_miwlab_backup_course returned with it'),
            'offset' => new external_value(PARAM_INT, 'Where the part starts, in bytes', VALUE_DEFAULT, 0),
            'length' => new external_value(PARAM_INT, 'At most this many bytes (up to 4 MB)', VALUE_DEFAULT, exporter::MAX_CHUNK),
        ]);
    }

    /**
     * Returns the part, to the request that queued the backup and can back up the content of that course.
     *
     * @param int $backupid
     * @param string $secret
     * @param int $offset
     * @param int $length
     * @return array
     */
    public static function execute(int $backupid, string $secret, int $offset = 0, int $length = exporter::MAX_CHUNK): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['backupid' => $backupid, 'secret' => $secret, 'offset' => $offset, 'length' => $length]
        );
        $record = get_backup_status::record($params['backupid'], $params['secret']);
        return exporter::chunk($record, $params['offset'], $params['length']);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'offset' => new external_value(PARAM_INT, 'Where the part starts'),
            'length' => new external_value(PARAM_INT, 'Bytes in the part'),
            'data' => new external_value(PARAM_RAW, 'The part, in base64'),
            'eof' => new external_value(PARAM_BOOL, 'The part reaches the end of the file'),
        ]);
    }
}
