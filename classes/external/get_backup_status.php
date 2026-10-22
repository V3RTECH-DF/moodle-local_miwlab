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
 * Progress of a content backup queued by local_miwlab_backup_course, for the request that queued it (its secret); finished,
 * the size and SHA-1 of the file, to download in parts with local_miwlab_get_backup_chunk.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_backup_status extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'backupid' => new external_value(PARAM_INT, 'Identifier returned by local_miwlab_backup_course'),
            'secret' => new external_value(PARAM_ALPHANUM, 'The secret local_miwlab_backup_course returned with it'),
        ]);
    }

    /**
     * Returns the status, to whoever can back up the content of that course.
     *
     * @param int $backupid
     * @param string $secret
     * @return array
     */
    public static function execute(int $backupid, string $secret): array {
        $params = self::validate_parameters(self::execute_parameters(), ['backupid' => $backupid, 'secret' => $secret]);
        return exporter::export(self::record($params['backupid'], $params['secret']));
    }

    /**
     * The record of the request that queued it (its secret), checked against the capabilities in its course.
     *
     * @param int $backupid
     * @param string $secret
     * @return \stdClass
     */
    public static function record(int $backupid, string $secret): \stdClass {
        $record = exporter::record($backupid, $secret);
        $course = exporter::course((int) $record->courseid);
        self::validate_category_context((int) $course->category);
        exporter::require_capabilities(\context_course::instance($course->id));
        return $record;
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'backupid' => new external_value(PARAM_INT, 'Identifier of the content backup'),
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'status' => new external_value(PARAM_ALPHA, 'queued, running, finished or failed'),
            'filename' => new external_value(PARAM_FILE, 'Name of the backup file, when finished'),
            'filesize' => new external_value(PARAM_INT, 'Size of the backup file in bytes, when finished'),
            'contenthash' => new external_value(PARAM_ALPHANUM, 'SHA-1 of the backup file, when finished'),
            'errorcode' => new external_value(PARAM_RAW, 'Code of the failure (error:...), when failed'),
            'errormessage' => new external_value(PARAM_RAW, 'Readable reason of the failure, when failed'),
            'timecreated' => new external_value(PARAM_INT, 'When it was queued'),
            'timefinished' => new external_value(PARAM_INT, 'When it ended; 0 before'),
        ]);
    }
}
