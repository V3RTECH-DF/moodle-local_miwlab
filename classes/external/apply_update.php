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

use local_miwlab\local\fullcopies;
use local_miwlab\local\replacer;
use local_miwlab\local\updater;

/**
 * Queues the application of a preview of a safe update (1.9.0, issue 204): exactly what the preview showed. Refused if the course
 * changed since, if the preview expired or was applied, if another operation is running in the course, or if the preview
 * replaces the
 * completion of a course somebody concluded and the conscious confirmation was not given.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class apply_update extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'previewid' => new external_value(PARAM_ALPHANUM, 'The preview (local_miwlab_preview_update)'),
            'forcediscard' => new external_value(
                PARAM_BOOL,
                'The conscious confirmation: somebody who concluded the course loses it (the preview says who); '
                    . 'a full backup is made first',
                VALUE_DEFAULT,
                false
            ),
            'keepdays' => new external_value(
                PARAM_INT,
                'Days (30 to 365) the full backup is kept, when one is made',
                VALUE_DEFAULT,
                fullcopies::DEFAULT_DAYS
            ),
        ]);
    }

    /**
     * Queues it.
     *
     * @param string $previewid
     * @param bool $forcediscard
     * @param int $keepdays
     * @return array
     */
    public static function execute(string $previewid, bool $forcediscard = false, int $keepdays = fullcopies::DEFAULT_DAYS): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'previewid' => $previewid,
            'forcediscard' => $forcediscard,
            'keepdays' => $keepdays,
        ]);
        $record = updater::open_preview($params['previewid']);
        $course = replacer::course((int) $record->courseid);
        self::validate_category_context((int) $course->category);
        $row = updater::queue($record, $params['forcediscard'], $params['keepdays']);
        return \local_miwlab\local\restorer::export($row);
    }

    /**
     * Returned structure: the same as local_miwlab_get_restore_status.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return get_restore_status::execute_returns();
    }
}
