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

use local_miwlab\local\forum_topics;
use local_miwlab\local\replacer;
use local_miwlab\local\restorer;

/**
 * Creates, in a course that already exists, the opening topics the MiwLab marked (1.13.0, issue 347 of the MiwLab).
 *
 * The safe update never restores a package of the whole course, so the topics of the forums did not come with it. The MiwLab calls
 * this after the update finished: it sends a small package (miwlab/foruns.json and the files of the topics, each forum told by its
 * course module). The topics that are not in their forum yet (same forum, same title) are created, as the account of the
 * integration, without any notification; a topic that is already there is left as it is, even if the MiwLab changed it. What
 * could not be created is counted, with the first reason. Nothing else in the course is touched.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_forum_topics extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft area where the package of the topics was uploaded'),
            'filename' => new external_value(
                PARAM_FILE,
                'Name of the package in the draft area; empty: the only file there',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Creates the topics the package carries.
     *
     * @param int $courseid
     * @param int $draftitemid
     * @param string $filename
     * @return array
     */
    public static function execute(int $courseid, int $draftitemid, string $filename = ''): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['courseid' => $courseid, 'draftitemid' => $draftitemid, 'filename' => $filename]
        );
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        require_capability('local/miwlab:updatecourse', $context);
        $file = restorer::draft_file($params['draftitemid'], $params['filename']);
        $result = forum_topics::create_from_archive((int) $course->id, $file);
        $file->delete();
        return ['courseid' => (int) $course->id] + $result;
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'created' => new external_value(PARAM_INT, 'Topics created'),
            'existing' => new external_value(PARAM_INT, 'Topics that were already in their forum (left as they are)'),
            'skipped' => new external_value(PARAM_INT, 'Topics not created'),
            'note' => new external_value(PARAM_RAW, 'The first reason a topic was not created; empty when all were'),
        ]);
    }
}
