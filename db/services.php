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

/**
 * Web service functions of the MiwLab companion plugin.
 *
 * Add them to the external service used by the MiwLab key. Uploading the package to the draft area
 * (webservice/upload.php) needs "Can upload files" enabled in that service.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_miwlab_get_info' => [
        'classname' => 'local_miwlab\external\get_info',
        'description' => 'Version of the MiwLab companion plugin and what it offers.',
        'type' => 'read',
        'capabilities' => '',
    ],
    'local_miwlab_get_restore_categories' => [
        'classname' => 'local_miwlab\external\get_restore_categories',
        'description' => 'Categories where the account can restore a MiwLab package as a new course.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:restorecourse, moodle/restore:restorecourse, moodle/course:create',
    ],
    'local_miwlab_restore_course' => [
        'classname' => 'local_miwlab\external\restore_course',
        'description' => 'Queues the restore, as a new course in a category, of a package already uploaded to the draft area.',
        'type' => 'write',
        'capabilities' => 'local/miwlab:restorecourse, moodle/restore:restorecourse, moodle/course:create',
    ],
    'local_miwlab_get_restore_status' => [
        'classname' => 'local_miwlab\external\get_restore_status',
        'description' => 'Progress of a restore queued by local_miwlab_restore_course.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:restorecourse',
    ],
    'local_miwlab_find_courses' => [
        'classname' => 'local_miwlab\external\find_courses',
        'description' => 'Courses whose ID number carries a MiwLab marker.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
    'local_miwlab_get_course_completion' => [
        'classname' => 'local_miwlab\external\get_course_completion',
        'description' => 'Consolidated activity completion of a course: counts only, no person.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
    'local_miwlab_get_course_feedbacks' => [
        'classname' => 'local_miwlab\external\get_course_feedbacks',
        'description' => 'Feedback activities of a course, whether each is anonymous, and their questions.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
    'local_miwlab_get_feedback_analysis' => [
        'classname' => 'local_miwlab\external\get_feedback_analysis',
        'description' => 'Consolidated results of an anonymous feedback; open answers only when asked.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
];
