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
 * The plugin declares the "MiwLab (local_miwlab)" external service with them (and the core functions the MiwLab calls);
 * a service made by hand works too. Uploading the package to the draft area (webservice/upload.php) needs "Can upload
 * files" in it.
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
    'local_miwlab_get_module_types' => [
        'classname' => 'local_miwlab\external\get_module_types',
        'description' => 'Activity and resource types installed on the site: enabled or not, standard or add-on.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewmoduletypes',
    ],
    'local_miwlab_get_replace_check' => [
        'classname' => 'local_miwlab\external\get_replace_check',
        'description' => 'Before replacing a course: how many people are enrolled and how many have progress there (counts only).',
        'type' => 'read',
        'capabilities' => 'local/miwlab:replacecourse',
    ],
    'local_miwlab_replace_course' => [
        'classname' => 'local_miwlab\external\replace_course',
        'description' => 'Queues the replacement of the content of a course by a package, after a backup; refused with progress.',
        'type' => 'write',
        'capabilities' => 'local/miwlab:replacecourse, moodle/backup:backupcourse, moodle/restore:restorecourse, '
            . 'moodle/restore:configure, moodle/restore:rolldates',
    ],
    'local_miwlab_get_course_state' => [
        'classname' => 'local_miwlab\external\get_course_state',
        'description' => 'State of the content of a course (a signature and the latest edit), to know whether it changed.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
    'local_miwlab_get_forum_types' => [
        'classname' => 'local_miwlab\external\get_forum_types',
        'description' => 'The type of each forum of a course (general, qanda, single, eachuser, blog).',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewreports',
    ],
    'local_miwlab_get_forum_topics' => [
        'classname' => 'local_miwlab\external\get_forum_topics',
        'description' => 'The opening topics of the forums of a course that its staff started (teachers, managers), with files.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:readforumtopics',
    ],
    'local_miwlab_hide_course' => [
        'classname' => 'local_miwlab\external\hide_course',
        'description' => 'Hides a course, once a new course takes its place.',
        'type' => 'write',
        'capabilities' => 'local/miwlab:hidecourse, moodle/course:visibility',
    ],
    'local_miwlab_get_course_formats' => [
        'classname' => 'local_miwlab\external\get_course_formats',
        'description' => 'Course formats enabled on the site, with their names: the ones a course can take.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewmoduletypes',
    ],
    'local_miwlab_get_certificate_templates' => [
        'classname' => 'local_miwlab\external\get_certificate_templates',
        'description' => 'Certificate templates of the site (tool_certificate), for the Course certificate activity: id, name and '
            . 'where each belongs.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewmoduletypes',
    ],
    'local_miwlab_get_upload_limits' => [
        'classname' => 'local_miwlab\external\get_upload_limits',
        'description' => 'Limits that can refuse the file sent by the web service upload (PHP and site maximum size), in bytes.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewmoduletypes',
    ],
    'local_miwlab_backup_course' => [
        'classname' => 'local_miwlab\external\backup_course',
        'description' => 'Queues the backup of the content of a course, without any data of a person, for the MiwLab to download.',
        'type' => 'write',
        'capabilities' => 'local/miwlab:backupcourse, moodle/backup:backupcourse',
    ],
    'local_miwlab_get_backup_status' => [
        'classname' => 'local_miwlab\external\get_backup_status',
        'description' => 'Progress of a content backup; finished, the path to download it.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:backupcourse, moodle/backup:backupcourse',
    ],
    'local_miwlab_get_backup_chunk' => [
        'classname' => 'local_miwlab\external\get_backup_chunk',
        'description' => 'A part of a finished content backup (base64), for the request that queued it (its secret).',
        'type' => 'read',
        'capabilities' => 'local/miwlab:backupcourse, moodle/backup:backupcourse',
    ],
    'local_miwlab_delete_backup' => [
        'classname' => 'local_miwlab\external\delete_backup',
        'description' => 'Deletes a content backup once the MiwLab downloaded it.',
        'type' => 'write',
        'capabilities' => 'local/miwlab:backupcourse, moodle/backup:backupcourse',
    ],
    'local_miwlab_get_blocks' => [
        'classname' => 'local_miwlab\external\get_blocks',
        'description' => 'Blocks installed on the site, with their names and whether each is enabled.',
        'type' => 'read',
        'capabilities' => 'local/miwlab:viewmoduletypes',
    ],
];

// The service of the MiwLab key, with every function the MiwLab calls (the core ones too; the anonymous feedback is read by
// the plugin's own functions, so mod_feedback_* are not here). Moodle creates it on install,
// enabled and restricted to authorised users, and removes it, with its tokens, on uninstall. The account is authorised
// and the token created only when a site administrator clicks "Generate the MiwLab key" on the plugin page.
$services = [
    'MiwLab (local_miwlab)' => [
        'shortname' => 'local_miwlab',
        'enabled' => 1,
        'restrictedusers' => 1,
        'uploadfiles' => 1,
        'downloadfiles' => 0,
        'functions' => [
            'core_webservice_get_site_info',
            'core_course_get_courses_by_field',
            'core_course_search_courses',
            'core_course_get_contents',
            'core_course_get_categories',
            'local_miwlab_get_info',
            'local_miwlab_get_module_types',
            'local_miwlab_get_course_formats',
            'local_miwlab_get_blocks',
            'local_miwlab_get_certificate_templates',
            'local_miwlab_get_upload_limits',
            'local_miwlab_get_restore_categories',
            'local_miwlab_restore_course',
            'local_miwlab_get_restore_status',
            'local_miwlab_get_replace_check',
            'local_miwlab_replace_course',
            'local_miwlab_hide_course',
            'local_miwlab_get_course_state',
            'local_miwlab_get_forum_types',
            'local_miwlab_get_forum_topics',
            'local_miwlab_backup_course',
            'local_miwlab_get_backup_status',
            'local_miwlab_get_backup_chunk',
            'local_miwlab_delete_backup',
            'local_miwlab_find_courses',
            'local_miwlab_get_course_completion',
            'local_miwlab_get_course_feedbacks',
            'local_miwlab_get_feedback_analysis',
        ],
    ],
];
