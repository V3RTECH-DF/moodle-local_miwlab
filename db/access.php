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
 * Capabilities of the MiwLab companion plugin.
 *
 * Restoring also needs the core capabilities moodle/restore:restorecourse and moodle/course:create
 * in the same category; reading the consolidated reports needs nothing else. Replacing the content of a course
 * (1.5.0) also needs, in the course, moodle/backup:backupcourse (the copy made before), moodle/restore:restorecourse,
 * moodle/restore:configure (to keep enrolments, roles and groups) and moodle/restore:rolldates (to keep the dates of
 * the course); hiding a course also needs moodle/course:visibility. Recreating the opening topics of the forums after a restore
 * (1.7.0) also needs, in the restored course, mod/forum:startdiscussion (mod/forum:addnews for the announcements forum),
 * mod/forum:createattachment and mod/forum:pindiscussions.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Restore a MiwLab package as a new course in a category.
    'local/miwlab:restorecourse' => [
        'riskbitmask' => RISK_XSS | RISK_CONFIG,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSECAT,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Read consolidated counts of a course (completion and anonymous feedback), never data of a person.
    'local/miwlab:viewreports' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Replace the content of an existing course with a MiwLab package, after a backup of it, when nobody has progress there
    // (1.5.0); count the people enrolled and with progress, to decide.
    'local/miwlab:replacecourse' => [
        'riskbitmask' => RISK_XSS | RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Update a course in place, only what is safe for the people who study in it (1.9.0, issue 204): names and texts of the
    // activities, access restrictions, visibility, order, and the new activities and sections; hides what the MiwLab removed.
    // Never deletes and never touches a grade, a submission or a post. The only thing that can make somebody lose something is the
    // person's explicit choice to replace the completion criteria of a course somebody concluded (the preview says how many), and
    // then only with the conscious confirmation and after a full backup (hence RISK_DATALOSS).
    'local/miwlab:updatecourse' => [
        'riskbitmask' => RISK_XSS | RISK_CONFIG | RISK_DATALOSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Hide a course, for the MiwLab, once a new course takes its place (1.5.0).
    'local/miwlab:hidecourse' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Back up the CONTENT of a course for the MiwLab, without any data of a person, and download it (1.6.0, issue 155).
    'local/miwlab:backupcourse' => [
        'riskbitmask' => RISK_CONFIG,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // Read the opening topics that the staff of a course started in its forums, to send them again with the package (1.7.0,
    // issue 178). A topic started by anyone else is never read.
    'local/miwlab:readforumtopics' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    // List the activity and resource types and the course formats installed on the site (names and versions only).
    'local/miwlab:viewmoduletypes' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
