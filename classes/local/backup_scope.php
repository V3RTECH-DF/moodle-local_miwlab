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

use base_setting;
use moodle_exception;

/**
 * What a backup made by the plugin carries about people (1.9.0, issue 204), set and checked in one place.
 *
 * Before 1.9.0 the role of the plugin lacked moodle/backup:userinfo, and Moodle itself kept every backup without people. The role
 * now has it, because the backup made before replacing a course with a conscious discard carries them. So the other backups can
 * no longer rely on the permission: each one sets the settings explicitly and checks them AFTER setting, and refuses when any is
 * still on (the site may have locked "include users" on). Without this, giving the permission would make the copy made before
 * replacing without discard, and the content backup, follow the site default and carry people.
 *
 * Moodle locks EVERY setting "by permission" when the account lacks moodle/backup:configure (the role of the plugin lacks it), and
 * a setting locked that way keeps the site default and refuses set_value(). The plugin builds the backup itself and decides what
 * its copies carry, so it lifts that lock (and only that one), setting by setting, right before setting it. A lock made by the
 * site configuration (Site administration > Courses > Backups > General backup defaults) is never lifted, because unlocking would
 * also give the setting back its original value, undoing what the site fixed: it is the site saying what its backups carry, and
 * then the backup is refused, not forced. Moodle itself overwrites a lock of the site configuration with the lock by permission
 * for such an account, so the plugin reads the site configuration (backup_general_<setting>_locked) itself to know what the site
 * fixed.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_scope {
    /** The root settings known to carry data of a person (xapistate exists from Moodle 4.2); users first, then the rest. */
    const PEOPLE_SETTINGS = ['users', 'role_assignments', 'userscompletion', 'logs', 'grade_histories', 'comments', 'xapistate'];

    /**
     * The ONLY root settings proved to carry no data of a person (checked in Moodle 4.1 and 5.3): the name of the file, the
     * activities, blocks, filters, files, course calendar events, question bank, course competencies, custom fields, content
     * bank, legacy files, and the definitions of badges and groups (who earned or belongs needs users). The backup without
     * people fails closed: EVERY other root setting, known or added by a later Moodle, must be off, so a new setting that
     * depends on people cannot slip in through a list of the known ones.
     */
    const SAFE_ROOT = ['filename', 'activities', 'blocks', 'filters', 'files', 'calendarevents', 'questionbank', 'competencies',
        'customfield', 'contentbankcontent', 'legacyfiles', 'badges', 'groups'];

    /** Turned on in the full backup where the Moodle has them (xapistate: the xAPI state of each person, from 4.2). */
    const FULL_ON_IF_EXISTS = ['xapistate'];

    /**
     * The settings the full backup needs on (people, roles, completion, grade history, comments, badges, groups). Logs stay
     * off, and
     * so does the anonymisation (an anonymised copy does not give the people back).
     */
    const FULL_ON = ['users', 'role_assignments', 'userscompletion', 'grade_histories', 'comments', 'badges', 'groups'];

    /** The names the site configuration (Site administration > Courses > Backups > General backup defaults) uses, by setting. */
    const CONFIG_NAMES = ['users' => 'backup_general_users', 'anonymize' => 'backup_general_anonymize',
        'role_assignments' => 'backup_general_role_assignments', 'userscompletion' => 'backup_general_userscompletion',
        'logs' => 'backup_general_logs', 'grade_histories' => 'backup_general_histories', 'comments' => 'backup_general_comments',
        'badges' => 'backup_general_badges', 'groups' => 'backup_general_groups'];

    /** The settings the full backup needs off. */
    const FULL_OFF = ['logs', 'anonymize'];

    /**
     * Sets, when the site allows, every setting that carries people to off, and checks (see require_without_people()).
     *
     * @param \backup_plan $plan
     * @throws moodle_exception error:backupwithpeople
     */
    public static function exclude_people(\backup_plan $plan): void {
        self::set($plan, 'users', 0);
        foreach (self::PEOPLE_SETTINGS as $name) {
            self::set($plan, $name, 0);
        }
        foreach (self::root_settings_of_people($plan) as $setting) {
            self::set($plan, $setting->get_name(), 0);
        }
        foreach (self::user_data_settings($plan) as $setting) {
            self::set($plan, $setting->get_name(), 0);
        }
        self::require_without_people($plan);
    }

    /**
     * Refuses when any setting that carries people is still on: the root ones and the "include user data" of each activity
     * and block.
     *
     * @param \backup_plan $plan
     * @throws moodle_exception error:backupwithpeople
     */
    public static function require_without_people(\backup_plan $plan): void {
        foreach (self::root_settings_of_people($plan) as $setting) {
            if ($setting->get_value()) {
                throw new moodle_exception('error:backupwithpeople', 'local_miwlab');
            }
        }
        foreach (self::user_data_settings($plan) as $setting) {
            if ($setting->get_value()) {
                throw new moodle_exception('error:backupwithpeople', 'local_miwlab');
            }
        }
    }

    /**
     * Sets, when the site allows, the settings of the full backup (with the people) and checks them after setting.
     *
     * @param \backup_plan $plan
     * @param \context_course $context The course, where the account needs moodle/backup:userinfo.
     * @throws moodle_exception error:fullbackupnotallowed, naming the settings that did not stay as needed
     */
    public static function include_people(\backup_plan $plan, \context_course $context): void {
        if (!has_capability('moodle/backup:userinfo', $context)) {
            throw new moodle_exception('error:fullbackupnotallowed', 'local_miwlab', '', 'moodle/backup:userinfo');
        }
        // The "users" setting first: the others depend on it and unlock only after it is on.
        foreach (array_merge(self::FULL_ON, self::FULL_ON_IF_EXISTS) as $name) {
            self::set($plan, $name, 1);
        }
        foreach (self::FULL_OFF as $name) {
            self::set($plan, $name, 0);
        }
        foreach (self::user_data_settings($plan) as $setting) {
            self::set($plan, $setting->get_name(), 1);
        }
        $wrong = [];
        foreach (self::FULL_ON as $name) {
            if (!$plan->setting_exists($name) || !$plan->get_setting($name)->get_value()) {
                $wrong[] = $name;
            }
        }
        foreach (self::FULL_ON_IF_EXISTS as $name) {
            if ($plan->setting_exists($name) && !$plan->get_setting($name)->get_value()) {
                $wrong[] = $name;
            }
        }
        foreach (self::FULL_OFF as $name) {
            if ($plan->setting_exists($name) && $plan->get_setting($name)->get_value()) {
                $wrong[] = $name;
            }
        }
        foreach (self::user_data_settings($plan) as $setting) {
            if (!$setting->get_value()) {
                $wrong[] = $setting->get_name();
            }
        }
        if ($wrong) {
            throw new moodle_exception('error:fullbackupnotallowed', 'local_miwlab', '', implode(', ', array_unique($wrong)));
        }
    }

    /**
     * Whether the site configuration fixed this setting (its "locked" box in the general backup defaults).
     *
     * @param string $name The setting.
     * @return bool
     */
    protected static function locked_by_the_site(string $name): bool {
        // The name the site configuration uses is backup_general_<setting>, except where Moodle spells it otherwise (CONFIG_NAMES).
        $config = self::CONFIG_NAMES[$name] ?? 'backup_general_' . $name;
        return !empty(get_config('backup', $config . '_locked'));
    }

    /**
     * Sets a setting when it exists and the site configuration did not lock it (a lock by permission is lifted, see the class).
     *
     * @param \backup_plan $plan
     * @param string $name
     * @param int $value
     */
    protected static function set(\backup_plan $plan, string $name, int $value): void {
        if (!$plan->setting_exists($name) || self::locked_by_the_site($name)) {
            return;
        }
        $setting = $plan->get_setting($name);
        if ($setting->get_status() == base_setting::LOCKED_BY_PERMISSION) {
            $setting->set_status(base_setting::NOT_LOCKED);
        }
        // A setting locked "by hierarchy" (its parent is off) still takes a value from set_value(): Moodle only refuses the
        // locks by permission and by configuration. That matters: a parent that the site fixed off leaves the settings under
        // it with the value of their own defaults (comments, role assignments, completion), and those would still go in the
        // backup.
        if (in_array($setting->get_status(), [base_setting::NOT_LOCKED, base_setting::LOCKED_BY_HIERARCHY])) {
            try {
                $setting->set_value($value);
            } catch (\Throwable $e) {
                // A setting that takes no such value stays as it is: the check after refuses the backup if it carries people.
                return;
            }
        }
    }

    /**
     * Every root setting that is not proved safe (SAFE_ROOT): the ones the backup without people needs off.
     *
     * @param \backup_plan $plan
     * @return \base_setting[]
     */
    protected static function root_settings_of_people(\backup_plan $plan): array {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $found = [];
        foreach ($plan->get_settings() as $setting) {
            if ($setting->get_level() == \backup_setting::ROOT_LEVEL && !in_array($setting->get_name(), self::SAFE_ROOT, true)) {
                $found[] = $setting;
            }
        }
        return $found;
    }

    /**
     * The "include user data" setting of each activity and block of the plan (named mod_N_userinfo, block_N_userinfo).
     *
     * @param \backup_plan $plan
     * @return \base_setting[]
     */
    protected static function user_data_settings(\backup_plan $plan): array {
        $found = [];
        foreach ($plan->get_settings() as $setting) {
            if (substr($setting->get_name(), -9) === '_userinfo') {
                $found[] = $setting;
            }
        }
        return $found;
    }
}
