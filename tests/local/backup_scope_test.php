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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\backup_scope::class)]
/**
 * Tests of what a backup made by the plugin carries about people (1.9.0, issue 204): the settings are set off or on explicitly and
 * checked after setting, including the "include user data" of each activity, and a lock of the site configuration is never lifted.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\backup_scope
 */
final class backup_scope_test extends \advanced_testcase {
    /**
     * A backup controller of a course with a page and a forum, as admin.
     *
     * @return array [controller, course]
     */
    protected function controller(): array {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        return [$bc, $course];
    }

    public function test_a_user_data_setting_of_an_activity_that_stays_on_refuses_the_backup(): void {
        $this->resetAfterTest();
        [$bc] = $this->controller();
        $plan = $bc->get_plan();
        backup_scope::exclude_people($plan);
        // Control: with everything off the check passes.
        backup_scope::require_without_people($plan);
        $found = [];
        foreach ($plan->get_settings() as $setting) {
            if (substr($setting->get_name(), -9) === '_userinfo' && strpos($setting->get_name(), 'page_') === 0) {
                $found[] = $setting;
            }
        }
        $this->assertCount(1, $found);
        // Moodle's dependencies would never leave it on while users is off; forced here to prove the check does not
        // rely on that.
        $value = new \ReflectionProperty(\base_setting::class, 'value');
        $value->setValue($found[0], true);
        try {
            backup_scope::require_without_people($plan);
            $this->fail('A user data setting that is on must refuse the backup.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:backupwithpeople', $e->errorcode);
        }
        $bc->destroy();
    }

    /**
     * An account like the key of the MiwLab: it may back up and back up user data, but lacks moodle/backup:configure, so Moodle
     * locks every setting of its backups "by permission" (overwriting the locks of the site configuration).
     *
     * @return \stdClass
     */
    protected function account_without_configure(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Key ' . $user->id, 'key' . $user->id, '');
        $system = \context_system::instance();
        foreach (['moodle/backup:backupcourse', 'moodle/backup:userinfo', 'moodle/course:view'] as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $system->id, true);
        }
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A controller of the course as the account.
     *
     * @param \stdClass $course
     * @param \stdClass $account
     * @return \backup_controller
     */
    protected function controller_as(\stdClass $course, \stdClass $account): \backup_controller {
        $this->setUser($account);
        return new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $account->id
        );
    }

    public function test_a_lock_of_the_site_configuration_is_not_lifted(): void {
        $this->resetAfterTest();
        [$bc, $course] = $this->controller();
        $bc->destroy();
        $account = $this->account_without_configure();

        // The site locks users ON: the backup without people is refused, not forced (the value the site fixed stays).
        set_config('backup_general_users', 1, 'backup');
        set_config('backup_general_users_locked', 1, 'backup');
        $bc = $this->controller_as($course, $account);
        $plan = $bc->get_plan();
        $this->assertSame(\base_setting::LOCKED_BY_PERMISSION, $plan->get_setting('users')->get_status());
        try {
            backup_scope::exclude_people($plan);
            $this->fail('The site locks users on: the backup without people must be refused, not forced.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:backupwithpeople', $e->errorcode);
        }
        $this->assertTrue((bool) $plan->get_setting('users')->get_value());
        $bc->destroy();

        // The site locks users OFF: the full backup is refused, and the one without people passes with everything under users off.
        set_config('backup_general_users', 0, 'backup');
        $bc = $this->controller_as($course, $account);
        $plan = $bc->get_plan();
        backup_scope::exclude_people($plan);
        foreach (['users', 'role_assignments', 'userscompletion', 'comments', 'grade_histories', 'logs'] as $name) {
            $this->assertFalse((bool) $plan->get_setting($name)->get_value(), $name);
        }
        $bc->destroy();
        $bc = $this->controller_as($course, $account);
        try {
            backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
            $this->fail('The site locks users off: the full backup must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:fullbackupnotallowed', $e->errorcode);
            $this->assertStringContainsString('users', $e->a);
        }
        $bc->destroy();

        // Control: with the site free, the same account gets the full backup and the one without people.
        set_config('backup_general_users', 1, 'backup');
        set_config('backup_general_users_locked', 0, 'backup');
        $bc = $this->controller_as($course, $account);
        backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
        $this->assertTrue((bool) $bc->get_plan()->get_setting('users')->get_value());
        $this->assertTrue((bool) $bc->get_plan()->get_setting('grade_histories')->get_value());
        $bc->destroy();
        $bc = $this->controller_as($course, $account);
        backup_scope::exclude_people($bc->get_plan());
        $this->assertFalse((bool) $bc->get_plan()->get_setting('users')->get_value());
        $bc->destroy();
    }

    public function test_the_xapi_state_of_the_people_goes_off_where_the_moodle_has_it(): void {
        // Review finding: a list of the known settings is not enough (xapistate, from Moodle 4.2, carries the xAPI state of
        // each person).
        $this->resetAfterTest();
        [$bc, $course] = $this->controller();
        $bc->destroy();
        $account = $this->account_without_configure();
        $bc = $this->controller_as($course, $account);
        $plan = $bc->get_plan();
        if (!$plan->setting_exists('xapistate')) {
            $this->markTestSkipped('This Moodle has no xapistate setting (it came with 4.2).');
        }
        // Control: left alone, the account (locked to the site default) would carry it.
        $this->assertTrue((bool) $plan->get_setting('xapistate')->get_value());
        backup_scope::exclude_people($plan);
        $this->assertFalse((bool) $plan->get_setting('xapistate')->get_value());
        $bc->destroy();
        // And the full backup turns it on.
        $bc = $this->controller_as($course, $account);
        backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
        $this->assertTrue((bool) $bc->get_plan()->get_setting('xapistate')->get_value());
        $bc->destroy();
    }

    public function test_a_site_that_locks_the_xapi_state_off_refuses_the_full_backup(): void {
        $this->resetAfterTest();
        [$bc, $course] = $this->controller();
        $bc->destroy();
        $account = $this->account_without_configure();
        set_config('backup_general_xapistate', 0, 'backup');
        set_config('backup_general_xapistate_locked', 1, 'backup');
        $bc = $this->controller_as($course, $account);
        if (!$bc->get_plan()->setting_exists('xapistate')) {
            $bc->destroy();
            $this->markTestSkipped('This Moodle has no xapistate setting (it came with 4.2).');
        }
        try {
            backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
            $this->fail('The site fixed the xAPI state off: the full backup must be refused, not turn it on.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:fullbackupnotallowed', $e->errorcode);
            $this->assertStringContainsString('xapistate', $e->a);
        }
        $bc->destroy();
        // Control: the site free, the same account gets it.
        set_config('backup_general_xapistate_locked', 0, 'backup');
        $bc = $this->controller_as($course, $account);
        backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
        $this->assertTrue((bool) $bc->get_plan()->get_setting('xapistate')->get_value());
        $bc->destroy();
    }

    /**
     * Adds a setting to the plan as a task would (the plan keeps them by name).
     *
     * @param \backup_plan $plan
     * @param \base_setting $setting
     */
    protected function add_to_plan(\backup_plan $plan, \base_setting $setting): void {
        $property = new \ReflectionProperty(\base_plan::class, 'settings');
        $settings = $property->getValue($plan);
        $settings[$setting->get_name()] = $setting;
        $property->setValue($plan, $settings);
    }

    public function test_a_root_setting_nobody_listed_fails_closed(): void {
        $this->resetAfterTest();
        [$bc] = $this->controller();
        $plan = $bc->get_plan();
        // A setting a later Moodle could add: on, free. The backup without people turns it off instead of carrying it.
        $free = new \backup_generic_setting('futuresetting', \base_setting::IS_BOOLEAN, true);
        $this->add_to_plan($plan, $free);
        backup_scope::exclude_people($plan);
        $this->assertFalse((bool) $free->get_value());
        // Locked on by the site: refused.
        $locked = new \backup_generic_setting('futurelocked', \base_setting::IS_BOOLEAN, true);
        $this->add_to_plan($plan, $locked);
        $locked->set_status(\base_setting::LOCKED_BY_CONFIG);
        try {
            backup_scope::exclude_people($plan);
            $this->fail('A root setting that is on and cannot be turned off must refuse the backup.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:backupwithpeople', $e->errorcode);
        }
        // Control: the settings proved safe stay on (the activities are in the backup).
        $this->assertTrue((bool) $plan->get_setting('activities')->get_value());
        $bc->destroy();
    }

    public function test_the_full_backup_needs_the_permission_of_the_account(): void {
        $this->resetAfterTest();
        [$bc, $course] = $this->controller();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        try {
            backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
            $this->fail('Without moodle/backup:userinfo the full backup must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:fullbackupnotallowed', $e->errorcode);
            $this->assertStringContainsString('moodle/backup:userinfo', $e->a);
        }
        // Control: the administrator may.
        $this->setAdminUser();
        backup_scope::include_people($bc->get_plan(), \context_course::instance($course->id));
        $this->assertTrue((bool) $bc->get_plan()->get_setting('users')->get_value());
        $bc->destroy();
    }
}
