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
 * Upgrade steps of the MiwLab companion plugin.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_miwlab_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    if ($oldversion < 2026101910) {
        // 1.2.0: the system role of the MiwLab key, with the capability to list the module types.
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026101910, 'local', 'miwlab');
    }
    if ($oldversion < 2026101911) {
        // The role reads hidden sections, hidden activities and restricted ones instead of holding moodle/course:update, and
        // the service no longer carries mod_feedback_* (the plugin reads the feedback): the role is reset to the new list.
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026101911, 'local', 'miwlab');
    }
    if ($oldversion < 2026101920) {
        // 1.3.0: the service of the plugin is renamed "MiwLab (local_miwlab)" (issue 143): a service made by hand with that
        // name gets another one, and the service of 1.2.0 takes it, before Moodle reads db/services.php right after this
        // step (it would otherwise delete it, with its tokens). The role is reset. From Moodle 5.3 on, the token of an
        // account whose authentication method is disabled is refused: the web service authentication of the plugin
        // account is turned on when the account exists.
        \local_miwlab\local\provisioning::prepare_service();
        \local_miwlab\local\provisioning::ensure_role();
        \local_miwlab\local\provisioning::enable_authentication();
        upgrade_plugin_savepoint(true, 2026101920, 'local', 'miwlab');
    }
    if ($oldversion < 2026102101) {
        // 1.5.0: replacing the content of a course (issue 150). The restores remember the mode, the backup made before,
        // whether it was restored after a failure and the state of the course the MiwLab knew; the role gets the capabilities
        // of the replacement and of hiding a course.
        $table = new xmldb_table('local_miwlab_restores');
        $fields = [
            new xmldb_field('mode', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'new', 'courseid'),
            new xmldb_field('backupfilename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'timefinished'),
            new xmldb_field('rolledback', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'backupfilename'),
            new xmldb_field('expectedsignature', XMLDB_TYPE_CHAR, '40', null, null, null, null, 'rolledback'),
            new xmldb_field('unchangedsince', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'expectedsignature'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        $index = new xmldb_index('courseid-mode', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'mode']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026102101, 'local', 'miwlab');
    }
    if ($oldversion < 2026102104) {
        // 1.6.0: replacing a course deletes the course badges of the old content, and a restore activates the badges the
        // package brings; the role gets moodle/badges:deletebadge and moodle/badges:configurecriteria.
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026102104, 'local', 'miwlab');
    }
    if ($oldversion < 2026102105) {
        // 1.6.0: the backup of the content of a course for the MiwLab (issue 155), without any data of a person; the role gets
        // local/miwlab:backupcourse.
        $table = new xmldb_table('local_miwlab_backups');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('filesize', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('contenthash', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('secrethash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('errorcode', XMLDB_TYPE_CHAR, '100', null, null, null, null);
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timefinished', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026102105, 'local', 'miwlab');
    }
    if ($oldversion < 2026102106) {
        // 1.6.0 (security review): the backup is downloaded in parts by local_miwlab_get_backup_chunk, only by the request that
        // queued it (a secret returned once, kept as its SHA-256); the service does not allow downloading files.
        $table = new xmldb_table('local_miwlab_backups');
        $field = new xmldb_field('secrethash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null, 'contenthash');
        if ($dbman->table_exists($table) && !$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Backups queued before the secret existed cannot be downloaded any more.
        // Their files go too (the area is in the course context, item = the record id).
        $fs = get_file_storage();
        foreach ($DB->get_records('local_miwlab_backups', ['secrethash' => '']) as $old) {
            $context = context_course::instance((int) $old->courseid, IGNORE_MISSING);
            if ($context) {
                $fs->delete_area_files($context->id, 'local_miwlab', 'contentbackup', (int) $old->id);
            }
        }
        $DB->delete_records('local_miwlab_backups', ['secrethash' => '']);
        upgrade_plugin_savepoint(true, 2026102106, 'local', 'miwlab');
    }
    if ($oldversion < 2026102107) {
        // 1.6.0 (security review): the queue function is a write; the CHAR columns of the backups table have no empty default
        // (XMLDB refuses it); a failed backup leaves no copy in the backup area. Nothing to change in the data.
        upgrade_plugin_savepoint(true, 2026102107, 'local', 'miwlab');
    }
    if ($oldversion < 2026102109) {
        // 1.7.0: the certificate templates of the site and the upload limits (issues 186 and 180). The two functions come to the
        // service of the plugin with the upgrade itself; no capability, table or setting changes.
        upgrade_plugin_savepoint(true, 2026102109, 'local', 'miwlab');
    }
    if ($oldversion < 2026102110) {
        // 1.7.0: the opening topics of the forums (issue 178). A restore remembers how many topics it recreated from the
        // package, how many it could not, and the first reason; the role gets the capability to read them and the forum
        // capabilities to start a discussion, attach a file and pin it.
        $table = new xmldb_table('local_miwlab_restores');
        $fields = [
            new xmldb_field('topicscreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'unchangedsince'),
            new xmldb_field('topicsskipped', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'topicscreated'),
            new xmldb_field('topicsnote', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'topicsskipped'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        \local_miwlab\local\provisioning::ensure_role();
        upgrade_plugin_savepoint(true, 2026102110, 'local', 'miwlab');
    }
    return true;
}
