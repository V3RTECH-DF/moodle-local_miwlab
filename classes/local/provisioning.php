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

namespace local_miwlab\local;

/**
 * The Moodle side of the MiwLab connection, set up by the plugin: the system role "MiwLab" (created on install and kept on
 * every upgrade), and, only when a site administrator asks for it on the plugin page, the integration account, its role
 * assignment, its authorisation in the "MiwLab" external service and its token.
 *
 * The external service itself is declared in db/services.php (Moodle creates it, enabled, with the functions the MiwLab
 * calls, and removes it with its tokens when the plugin is uninstalled).
 *
 * Nothing about a person is kept: the plugin remembers only the ids of the role and of the account it created.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provisioning {
    /** Short name of the external service (db/services.php). */
    const SERVICE = 'local_miwlab';
    /**
     * Name of the external service (the key of $services in db/services.php). Up to 1.2.0 it was "MiwLab", the name the
     * manual key instructions also gave to a service made by hand; external_services.name is unique, so updating a site
     * that had that service failed (issue 143).
     */
    const SERVICE_NAME = 'MiwLab (local_miwlab)';
    /** Name of the service up to 1.2.0. */
    const OLD_SERVICE_NAME = 'MiwLab';
    /** Short name of the system role the plugin creates. */
    const ROLE = 'local_miwlab';
    /** User name of the integration account the plugin creates. */
    const USERNAME = 'local_miwlab';
    /** Fictitious address of the account: it never receives e-mail (emailstop), and .invalid never resolves (RFC 2606). */
    const EMAIL = 'local_miwlab@example.invalid';

    /**
     * The least the functions of the service need, at system level, each for a function the MiwLab calls. The writes are
     * the restore of a package as a NEW course and, from 1.5.0, the replacement of the content of a course where nobody has
     * progress (after a backup) and hiding a course, each through a function of the plugin that checks its own capability.
     *
     * - webservice/rest:use: the REST protocol of the key.
     * - moodle/course:view: core_course_get_contents and core_course_get_courses_by_field read a course the account is not
     *   enrolled in (require_login in the course context).
     * - moodle/course:viewhiddencourses: the same, and core_course_search_courses, for hidden courses (a restored course
     *   arrives hidden).
     * - moodle/course:viewhiddensections, moodle/course:viewhiddenactivities, moodle/course:ignoreavailabilityrestrictions:
     *   core_course_get_contents returns the hidden sections, the hidden activities and the activities hidden by an access
     *   restriction, with the restriction itself (availability). Without them the structure the MiwLab compares comes
     *   incomplete. They replace moodle/course:update, which only adds the restrictions of visible activities and lets the
     *   account edit every course.
     * - moodle/category:viewhiddencategories: core_course_get_categories lists hidden categories too.
     * - moodle/restore:restorecourse, moodle/course:create, local/miwlab:restorecourse: local_miwlab_restore_course and
     *   local_miwlab_get_restore_categories (restore as a new course in a category).
     * - local/miwlab:viewreports: local_miwlab_get_course_completion, _get_course_feedbacks, _get_feedback_analysis,
     *   _find_courses (counts only).
     * - local/miwlab:viewmoduletypes: local_miwlab_get_module_types and local_miwlab_get_course_formats.
     * - local/miwlab:replacecourse, moodle/backup:backupcourse, moodle/restore:configure, moodle/restore:rolldates (1.5.0):
     *   local_miwlab_get_replace_check and local_miwlab_replace_course, which replace the CONTENT of a course where nobody has
     *   progress, after a backup of it, keeping enrolments, roles, groups and the course configuration (the restore settings
     *   need moodle/restore:configure; the dates follow the start of the course with moodle/restore:rolldates). No
     *   moodle/course:update: the course configuration is not overwritten.
     * - local/miwlab:hidecourse, moodle/course:visibility (1.5.0): local_miwlab_hide_course, for the old course once a new
     *   one takes its place.
     * - local/miwlab:backupcourse (1.6.0, with moodle/backup:backupcourse): local_miwlab_backup_course, _get_backup_status and
     *   _delete_backup, the backup of the CONTENT of a course without any data of a person (the role has no
     *   moodle/backup:userinfo), downloaded by the MiwLab to bring the whole content of the course.
     *
     * Plus every mod/<module>:view of the site (see capabilities()): core_course_get_contents skips an activity whose
     * mod/<module>:view the account lacks (cm_info::is_user_access_restricted_by_capability), so without them a quiz, an
     * assignment, a feedback or an H5P activity would not appear in the structure the MiwLab reads.
     */
    const CAPABILITIES = [
        'webservice/rest:use',
        'moodle/course:view',
        'moodle/course:viewhiddencourses',
        'moodle/course:viewhiddensections',
        'moodle/course:viewhiddenactivities',
        'moodle/course:ignoreavailabilityrestrictions',
        'moodle/category:viewhiddencategories',
        'moodle/restore:restorecourse',
        'moodle/course:create',
        'local/miwlab:restorecourse',
        'local/miwlab:viewreports',
        'local/miwlab:viewmoduletypes',
        'local/miwlab:replacecourse',
        'moodle/backup:backupcourse',
        'moodle/restore:configure',
        'moodle/restore:rolldates',
        'local/miwlab:hidecourse',
        'moodle/course:visibility',
        'moodle/badges:deletebadge',
        'moodle/badges:configurecriteria',
        'local/miwlab:backupcourse',
        // 1.6.0: a package with the content of a course imported from Moodle carries its question bank; the restore (new course or
        // replacement) creates the question categories and questions only for an account that can manage and add them there.
        'moodle/question:managecategory',
        'moodle/question:add',
        // 1.7.0 (issue 178): the opening topics of the forums. Reading the topics the staff started in a course; recreating them in
        // the restored course as the account of the integration (the news forum needs addnews, a file needs createattachment).
        'local/miwlab:readforumtopics',
        'mod/forum:startdiscussion',
        'mod/forum:addnews',
        'mod/forum:createattachment',
        'mod/forum:pindiscussions',
    ];

    /**
     * The capabilities of the role: the list above and every mod/<module>:view installed on the site (read only).
     *
     * @return string[]
     */
    public static function capabilities(): array {
        global $DB;
        $where = $DB->sql_like('name', ':mod') . ' AND ' . $DB->sql_like('name', ':view');
        $views = $DB->get_fieldset_select('capabilities', 'name', $where, ['mod' => 'mod/%', 'view' => '%:view']);
        sort($views);
        return array_values(array_unique(array_merge(self::CAPABILITIES, $views)));
    }

    /**
     * Creates the role if it is not there (or was deleted), and gives it exactly capabilities(), at system level only.
     * Called on install, on upgrade and on every key generated (a module installed later gets its view capability then).
     *
     * Called on install and on upgrade. A role with the same short name that the plugin did not create is left untouched.
     *
     * @return int the role id, or 0 when another role holds the short name
     */
    public static function ensure_role(): int {
        global $DB;
        // The capabilities of this plugin must exist before they are given: install.php and upgrade.php run before Moodle
        // reads db/access.php.
        update_capabilities('local_miwlab');
        $roleid = (int) get_config('local_miwlab', 'roleid');
        $role = $roleid ? $DB->get_record('role', ['id' => $roleid]) : false;
        if (!$role) {
            if ($DB->record_exists('role', ['shortname' => self::ROLE])) {
                return 0;
            }
            $roleid = create_role(
                get_string('rolename', 'local_miwlab'),
                self::ROLE,
                get_string('roledescription', 'local_miwlab')
            );
            set_config('roleid', $roleid, 'local_miwlab');
        }
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $system = \context_system::instance();
        $where = 'roleid = ? AND contextid = ?';
        $given = $DB->get_fieldset_select('role_capabilities', 'capability', $where, [$roleid, $system->id]);
        $capabilities = self::capabilities();
        foreach (array_diff($given, $capabilities) as $capability) {
            unassign_capability($capability, $roleid, $system->id);
        }
        foreach ($capabilities as $capability) {
            if (get_capability_info($capability)) {
                assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
            }
        }
        $system->mark_dirty();
        return $roleid;
    }

    /**
     * The external service of the plugin, as Moodle created it from db/services.php.
     *
     * @return \stdClass|false
     */
    public static function service() {
        global $DB;
        return $DB->get_record('external_services', ['shortname' => self::SERVICE, 'component' => 'local_miwlab']);
    }

    /**
     * Frees the name of the plugin service before Moodle reads db/services.php (install.php and upgrade.php run before
     * external_update_descriptions()): a service with that name that is not the plugin's (made by hand) gets another name,
     * and the service of 1.2.0 (named "MiwLab") takes the new name, so that Moodle keeps it, with its authorised users and
     * tokens, instead of deleting it and creating another. Functions, users and tokens of every service stay as they are.
     */
    public static function prepare_service(): void {
        global $DB;
        $select = 'name = ? AND (component IS NULL OR component <> ?)';
        foreach ($DB->get_records_select('external_services', $select, [self::SERVICE_NAME, 'local_miwlab']) as $other) {
            $name = self::free_service_name(self::SERVICE_NAME . ' (manual)');
            $DB->set_field('external_services', 'name', $name, ['id' => $other->id]);
        }
        $old = $DB->get_record('external_services', ['component' => 'local_miwlab', 'name' => self::OLD_SERVICE_NAME]);
        if ($old && !$DB->record_exists('external_services', ['name' => self::SERVICE_NAME])) {
            $DB->set_field('external_services', 'name', self::SERVICE_NAME, ['id' => $old->id]);
        }
    }

    /**
     * A service name nobody uses: the given one, or it followed by a number.
     *
     * @param string $name
     * @return string
     */
    private static function free_service_name(string $name): string {
        global $DB;
        $candidate = $name;
        for ($i = 2; $DB->record_exists('external_services', ['name' => $candidate]); $i++) {
            $candidate = $name . ' ' . $i;
        }
        return $candidate;
    }

    /**
     * The service of the plugin, created again from db/services.php when it is missing (an update that stopped half way,
     * issue 143, recorded the new version without creating it, and Notifications then has nothing to do).
     *
     * @return \stdClass|false
     */
    public static function ensure_service() {
        global $CFG;
        $service = self::service();
        if (!$service) {
            require_once($CFG->libdir . '/upgradelib.php');
            self::prepare_service();
            external_update_descriptions('local_miwlab');
            $service = self::service();
        }
        return $service;
    }

    /**
     * The integration account the plugin created, if it still exists.
     *
     * @return \stdClass|false
     */
    public static function account() {
        global $DB;
        $userid = (int) get_config('local_miwlab', 'accountid');
        return $userid ? $DB->get_record('user', ['id' => $userid, 'deleted' => 0]) : false;
    }

    /**
     * What is in place, for the plugin page.
     *
     * @return array
     */
    public static function status(): array {
        global $CFG, $DB;
        $service = self::service();
        $account = self::account();
        $protocols = empty($CFG->webserviceprotocols) ? [] : explode(',', $CFG->webserviceprotocols);
        $roleid = (int) get_config('local_miwlab', 'roleid');
        $tokens = ($service && $account)
            ? $DB->count_records('external_tokens', ['userid' => $account->id, 'externalserviceid' => $service->id]) : 0;
        return [
            'webservices' => !empty($CFG->enablewebservices),
            'rest' => in_array('rest', $protocols),
            'auth' => is_enabled_auth('webservice'),
            'service' => $service ? (bool) $service->enabled : null,
            'role' => $roleid > 0 && $DB->record_exists('role', ['id' => $roleid]),
            'account' => $account ? $account->username : null,
            'tokens' => $tokens,
        ];
    }

    /**
     * Turns on the web service authentication (auth_webservice), the one of the account, when the account exists. From Moodle 5.3
     * on, the web service server refuses the token of an account whose authentication method is disabled
     * (wsaccessusernologin); before, it only refused "nologin". Without the account nothing is changed.
     *
     * @return bool whether the account exists and its authentication is now on
     */
    public static function enable_authentication(): bool {
        if (!self::account()) {
            return false;
        }
        if (!is_enabled_auth('webservice')) {
            \core\plugininfo\auth::enable_plugin('webservice', 1);
        }
        return is_enabled_auth('webservice');
    }

    /**
     * Everything the MiwLab key needs, on the click of a site administrator: web services and REST on, the account (created
     * or the one the plugin created before) and its authentication method on, the role at system level, the authorisation in
     * the service, and a new token.
     * The tokens the account had for the service stop working.
     *
     * @return string the token
     */
    public static function generate_key(): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_capability('moodle/site:config', \context_system::instance());
        $service = self::ensure_service();
        if (!$service) {
            throw new \moodle_exception('error:noservice', 'local_miwlab');
        }
        $roleid = self::ensure_role();
        if (!$roleid) {
            throw new \moodle_exception('error:rolenameinuse', 'local_miwlab', '', self::ROLE);
        }

        set_config('enablewebservices', 1);
        $protocols = empty($CFG->webserviceprotocols) ? [] : explode(',', $CFG->webserviceprotocols);
        if (!in_array('rest', $protocols)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }
        if (!$service->enabled) {
            $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
        }

        $account = self::account();
        if (!$account) {
            $taken = ['username' => self::USERNAME, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0];
            if ($DB->record_exists('user', $taken)) {
                throw new \moodle_exception('error:usernameinuse', 'local_miwlab', '', self::USERNAME);
            }
            $user = (object) [
                'username' => self::USERNAME,
                'auth' => 'webservice',
                'firstname' => get_string('accountfirstname', 'local_miwlab'),
                'lastname' => get_string('accountlastname', 'local_miwlab'),
                'email' => self::EMAIL,
                'emailstop' => 1,
                'confirmed' => 1,
                'mnethostid' => $CFG->mnet_localhost_id,
                'description' => get_string('accountdescription', 'local_miwlab'),
            ];
            // Moodle 5.3 deprecated user_create_user() in favour of \core\user::create_user().
            $userid = method_exists(\core\user::class, 'create_user')
                ? \core\user::create_user($user, false, false)
                : user_create_user($user, false, false);
            set_config('accountid', $userid, 'local_miwlab');
            $account = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        } else if ($account->suspended) {
            $DB->set_field('user', 'suspended', 0, ['id' => $account->id]);
        }

        self::enable_authentication();

        $system = \context_system::instance();
        role_assign($roleid, $account->id, $system->id);
        if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $account->id])) {
            $DB->insert_record('external_services_users', (object) [
                'externalserviceid' => $service->id,
                'userid' => $account->id,
                'iprestriction' => null,
                'validuntil' => null,
                'timecreated' => time(),
            ]);
        }
        $DB->delete_records('external_tokens', ['userid' => $account->id, 'externalserviceid' => $service->id]);
        if (class_exists(\core_external\util::class)) {
            return \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                $account->id,
                $system,
                0,
                '',
                get_string('pluginname', 'local_miwlab')
            );
        }
        require_once($CFG->libdir . '/externallib.php');
        return external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $account->id, $system);
    }

    /**
     * Every token of the account for the service stops working (the account and its role stay, for a new key later).
     *
     * @return int how many tokens were removed
     */
    public static function revoke_key(): int {
        global $DB;
        require_capability('moodle/site:config', \context_system::instance());
        $service = self::service();
        $account = self::account();
        if (!$service || !$account) {
            return 0;
        }
        $count = $DB->count_records('external_tokens', ['userid' => $account->id, 'externalserviceid' => $service->id]);
        $DB->delete_records('external_tokens', ['userid' => $account->id, 'externalserviceid' => $service->id]);
        return $count;
    }

    /**
     * Deletes the account the plugin created (its tokens, its role assignment and its authorisation go with it).
     *
     * @return bool whether there was an account to delete
     */
    public static function remove_account(): bool {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        $account = self::account();
        unset_config('accountid', 'local_miwlab');
        if (!$account) {
            return false;
        }
        $DB->delete_records('external_tokens', ['userid' => $account->id]);
        $DB->delete_records('external_services_users', ['userid' => $account->id]);
        delete_user($account);
        return true;
    }

    /**
     * Uninstalling the plugin: the account and the role it created go too (Moodle removes the service and its tokens).
     */
    public static function uninstall(): void {
        global $DB;
        self::remove_account();
        $roleid = (int) get_config('local_miwlab', 'roleid');
        if ($roleid && $DB->record_exists('role', ['id' => $roleid])) {
            delete_role($roleid);
        }
        unset_config('roleid', 'local_miwlab');
    }
}
