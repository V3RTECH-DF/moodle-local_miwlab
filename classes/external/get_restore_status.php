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
 * Progress of a restore queued by local_miwlab_restore_course.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_restore_status extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'restoreid' => new external_value(PARAM_ALPHANUM, 'Identifier returned by local_miwlab_restore_course'),
        ]);
    }

    /**
     * Returns the status; whoever can restore in the category of the restore can follow it (a replacement: whoever can
     * replace the course).
     *
     * @param string $restoreid
     * @return array
     */
    public static function execute(string $restoreid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['restoreid' => $restoreid]);
        $record = $DB->get_record('local_miwlab_restores', ['restoreid' => $params['restoreid']]);
        if (!$record) {
            throw new \moodle_exception('error:restorenotfound', 'local_miwlab');
        }
        if (($record->mode ?? restorer::MODE_NEW) === replacer::MODE) {
            // A replacement is followed by whoever can replace that course.
            $course = replacer::course((int) $record->courseid);
            self::validate_category_context((int) $course->category);
            require_capability('local/miwlab:replacecourse', \context_course::instance($course->id));
            return restorer::export($record);
        }
        $context = \context_coursecat::instance($record->categoryid, IGNORE_MISSING);
        if (!$context) {
            throw new \moodle_exception('error:categorynotfound', 'local_miwlab');
        }
        self::validate_context($context);
        require_capability('local/miwlab:restorecourse', $context);
        return restorer::export($record);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'restoreid' => new external_value(PARAM_ALPHANUM, 'Identifier of the restore'),
            'status' => new external_value(PARAM_ALPHA, 'queued, running, finished or failed'),
            'progress' => new external_value(PARAM_FLOAT, 'From 0 to 1 while running; 1 when finished'),
            'categoryid' => new external_value(PARAM_INT, 'Category of the new course'),
            'courseid' => new external_value(PARAM_INT, 'The new course, when finished; 0 otherwise'),
            'courseurl' => new external_value(PARAM_URL, 'Address of the new course, when finished'),
            'coursefullname' => new external_value(PARAM_RAW, 'Full name of the new course, when finished'),
            'courseshortname' => new external_value(PARAM_RAW, 'Short name of the new course, when finished'),
            'marker' => new external_value(PARAM_RAW, 'Course ID number, when finished (the MiwLab marker, if it was free)'),
            'errorcode' => new external_value(PARAM_RAW, 'Code of the failure (error:...), when failed'),
            'errormessage' => new external_value(PARAM_RAW, 'Readable reason of the failure, when failed'),
            'timecreated' => new external_value(PARAM_INT, 'When it was queued'),
            'timestarted' => new external_value(PARAM_INT, 'When the background task started it; 0 before'),
            'timefinished' => new external_value(PARAM_INT, 'When it ended; 0 before'),
            'mode' => new external_value(
                PARAM_ALPHA,
                'new: a new course in the category; replace: the content of a course replaced'
            ),
            'backupfilename' => new external_value(
                PARAM_FILE,
                'Mode replace: the backup of the course made before, in its course backup area (Course > Restore); empty: none'
            ),
            'rolledback' => new external_value(
                PARAM_BOOL,
                'Mode replace, failed: the restore had deleted content and the backup was restored into the course'
            ),
            'coursesignature' => new external_value(
                PARAM_ALPHANUM,
                'When finished: the state of the content of the course right after (as local_miwlab_get_course_state)'
            ),
            'contentmodified' => new external_value(PARAM_INT, 'When finished: the latest edit of the content'),
            'topicscreated' => new external_value(
                PARAM_INT,
                'When finished: the forum topics recreated from the package (the opening topics the MiwLab sent)'
            ),
            'topicsskipped' => new external_value(PARAM_INT, 'When finished: the forum topics that could not be created'),
            'topicsnote' => new external_value(PARAM_RAW, 'When finished: the first reason a topic was not created'),
        ]);
    }
}
