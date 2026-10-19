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
 * Queues the restore of a package, already in the draft area of the account, as a new course in a category.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_course extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'categoryid' => new external_value(PARAM_INT, 'Category of the new course'),
            'draftitemid' => new external_value(
                PARAM_INT,
                'Draft area where the package was uploaded (itemid of webservice/upload.php)'
            ),
            'filename' => new external_value(
                PARAM_FILE,
                'Name of the package in the draft area; empty: the only file there',
                VALUE_DEFAULT,
                ''
            ),
            'marker' => new external_value(
                PARAM_RAW_TRIMMED,
                'MiwLab marker for the course ID number (miwlab:...); empty: the one the package carries, if any',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Checks everything that can be checked now and queues the restore.
     *
     * @param int $categoryid
     * @param int $draftitemid
     * @param string $filename
     * @param string $marker
     * @return array
     */
    public static function execute(int $categoryid, int $draftitemid, string $filename = '', string $marker = ''): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), [
            'categoryid' => $categoryid,
            'draftitemid' => $draftitemid,
            'filename' => $filename,
            'marker' => $marker,
        ]);
        if (!$DB->record_exists('course_categories', ['id' => $params['categoryid']])) {
            throw new \moodle_exception('error:categorynotfound', 'local_miwlab');
        }
        $context = self::validate_category_context($params['categoryid']);
        restorer::require_capabilities($context);
        if ($params['marker'] !== '' && !restorer::valid_marker($params['marker'])) {
            throw new \moodle_exception('error:invalidmarker', 'local_miwlab');
        }
        $file = restorer::draft_file($params['draftitemid'], $params['filename']);
        $record = restorer::queue($params['categoryid'], $file, $params['marker']);
        return restorer::export($record);
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
