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

use local_miwlab\local\replacer;
use local_miwlab\local\restorer;

/**
 * Queues the replacement of the content of a course by a package already in the draft area of the account (1.5.0). Refused when
 * somebody has progress in the course; the background task makes a backup of the course first and replaces nothing when it
 * fails.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replace_course extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course whose content is replaced'),
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
                'MiwLab marker for the course ID number, written only when the course has none or has a MiwLab one',
                VALUE_DEFAULT,
                ''
            ),
            'expectedsignature' => new external_value(
                PARAM_ALPHANUM,
                'The state of the course the MiwLab knows (signature of local_miwlab_get_course_state): refused if it changed',
                VALUE_DEFAULT,
                ''
            ),
            'unchangedsince' => new external_value(
                PARAM_INT,
                'Without the signature: refused if the content was edited after this time (Unix time); 0 none',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Checks everything that can be checked now and queues the replacement.
     *
     * @param int $courseid
     * @param int $draftitemid
     * @param string $filename
     * @param string $marker
     * @param string $expectedsignature
     * @param int $unchangedsince
     * @return array
     */
    public static function execute(
        int $courseid,
        int $draftitemid,
        string $filename = '',
        string $marker = '',
        string $expectedsignature = '',
        int $unchangedsince = 0
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'draftitemid' => $draftitemid,
            'filename' => $filename,
            'marker' => $marker,
            'expectedsignature' => $expectedsignature,
            'unchangedsince' => $unchangedsince,
        ]);
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        replacer::require_capabilities($context, $course);
        if ($params['marker'] !== '' && !restorer::valid_marker($params['marker'])) {
            throw new \moodle_exception('error:invalidmarker', 'local_miwlab');
        }
        if (replacer::in_progress($course->id)) {
            throw new \moodle_exception('error:replaceinprogress', 'local_miwlab');
        }
        replacer::require_no_progress($course->id);
        replacer::require_unchanged($course->id, $params['expectedsignature'], $params['unchangedsince']);
        $file = restorer::draft_file($params['draftitemid'], $params['filename']);
        $record = replacer::queue($course, $file, $params['marker'], $params['expectedsignature'], $params['unchangedsince']);
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
