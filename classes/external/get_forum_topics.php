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

/**
 * The opening topics of the forums of a course that the staff of the course started (1.7.0, issue 178 of the MiwLab).
 *
 * A restore without people does not bring the discussions back, so the MiwLab keeps the opening topic of each forum, to send it
 * again with the package. Only what a teacher (with or without editing rights), a manager or a site administrator started is
 * read: the title, the text and the files of the first post. A discussion started by a student is never read, and neither are
 * replies, grades or any name: the role of the author is the only thing said about the person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_forum_topics extends base {
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
     * Returns the topics, once the account is checked against local/miwlab:readforumtopics in the course.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute(int $courseid): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $course = $DB->get_record('course', ['id' => $params['courseid']]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new \moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        self::validate_category_context((int) $course->category);
        require_capability('local/miwlab:readforumtopics', \context_course::instance($course->id));
        return ['courseid' => (int) $course->id] + forum_topics::read($course);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'forums' => new external_multiple_structure(new external_single_structure([
                'cmid' => new external_value(PARAM_INT, 'The course module (the id of the activity in the course)'),
                'type' => new external_value(PARAM_ALPHANUMEXT, 'general, qanda, eachuser, blog or news'),
                'name' => new external_value(PARAM_RAW, 'Name of the forum'),
                'omittedtopics' => new external_value(PARAM_INT, 'Topics left out of this forum (too many, or too long)'),
                'topics' => new external_multiple_structure(new external_single_structure([
                    'discussionid' => new external_value(PARAM_INT, 'The discussion'),
                    'subject' => new external_value(PARAM_RAW, 'Title of the discussion'),
                    'message' => new external_value(PARAM_RAW, 'Text of the first post, as HTML (files as @@PLUGINFILE@@)'),
                    'pinned' => new external_value(PARAM_BOOL, 'The discussion is pinned'),
                    'created' => new external_value(PARAM_INT, 'When the first post was written'),
                    'authorrole' => new external_value(
                        PARAM_ALPHANUMEXT,
                        'Role that made the author staff: editingteacher, teacher, manager or admin'
                    ),
                    'omittedfiles' => new external_value(PARAM_INT, 'Files left out (too many or too large)'),
                    'files' => new external_multiple_structure(new external_single_structure([
                        'filename' => new external_value(PARAM_RAW, 'Name of the file (already a valid file name)'),
                        'mimetype' => new external_value(PARAM_RAW, 'Type of the file'),
                        'filesize' => new external_value(PARAM_INT, 'Bytes'),
                        'embedded' => new external_value(PARAM_BOOL, 'In the text of the post, not an attachment'),
                        'content' => new external_value(PARAM_RAW, 'The file (base64)'),
                    ])),
                ])),
            ])),
            'omittedtopics' => new external_value(PARAM_INT, 'Topics of the course left out, all forums'),
        ]);
    }
}
