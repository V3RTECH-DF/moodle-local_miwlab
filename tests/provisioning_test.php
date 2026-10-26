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

namespace local_miwlab;

use local_miwlab\local\provisioning;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\provisioning::class)]
/**
 * Tests of the Moodle side the plugin sets up: the service, the role, and the key generated on the click of an administrator.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\provisioning
 */
final class provisioning_test extends \advanced_testcase {
    public function test_install_creates_service_and_role_but_no_account(): void {
        global $DB;
        $this->resetAfterTest();
        $service = provisioning::service();
        $this->assertNotFalse($service);
        $this->assertSame('1', (string) $service->enabled);
        $this->assertSame('1', (string) $service->restrictedusers);
        $this->assertSame('1', (string) $service->uploadfiles);
        $where = 'externalserviceid = ?';
        $functions = $DB->get_fieldset_select('external_services_functions', 'functionname', $where, [$service->id]);
        $expected = ['core_webservice_get_site_info', 'core_course_get_contents', 'local_miwlab_get_module_types',
            'local_miwlab_get_course_formats', 'local_miwlab_get_blocks', 'local_miwlab_get_forum_types',
            'local_miwlab_restore_course',
            'local_miwlab_get_replace_check', 'local_miwlab_replace_course', 'local_miwlab_hide_course'];
        foreach ($expected as $f) {
            $this->assertContains($f, $functions);
        }
        $this->assertNotContains('core_course_get_course_content_items', $functions);
        $roleid = (int) get_config('local_miwlab', 'roleid');
        $this->assertGreaterThan(0, $roleid);
        $this->assertSame([CONTEXT_SYSTEM], array_values(array_map('intval', get_role_contextlevels($roleid))));
        $given = $DB->get_fieldset_select('role_capabilities', 'capability', 'roleid = ? AND permission = ?', [$roleid, CAP_ALLOW]);
        $this->assertEqualsCanonicalizing(provisioning::capabilities(), $given);
        // The fixed list, and every mod/<module>:view of the site (read only), so that the structure shows every activity.
        $this->assertContains('mod/assign:view', $given);
        $this->assertContains('mod/quiz:view', $given);
        foreach ($given as $capability) {
            $fixed = in_array($capability, provisioning::CAPABILITIES);
            $this->assertTrue($fixed || preg_match('~^mod/[a-z0-9_]+:view$~', $capability) === 1, $capability);
        }
        $this->assertNotContains('moodle/course:update', $given);
        // 1.6.0 (revisão de segurança): deleting the course badges when replacing, and activating the ones a restore brings.
        $this->assertContains('moodle/badges:deletebadge', $given);
        $this->assertContains('moodle/badges:configurecriteria', $given);
        // 1.6.0 (issue 155): the backup of the content of a course, and the question bank a package with that content brings.
        $this->assertContains('local/miwlab:backupcourse', $given);
        $this->assertContains('moodle/question:add', $given);
        $this->assertContains('moodle/question:managecategory', $given);
        $this->assertContains('local_miwlab_backup_course', $functions);
        $this->assertContains('local_miwlab_get_backup_status', $functions);
        $this->assertContains('local_miwlab_delete_backup', $functions);
        $this->assertContains('local_miwlab_get_backup_chunk', $functions);
        // 1.8.0 (issue 202): the batch read of the courses of some categories.
        $this->assertContains('local_miwlab_get_courses_overview', $functions);
        // Security review: the service never downloads files (the backup comes in parts, only to the request that queued it).
        $this->assertSame(0, (int) $service->downloadfiles);
        $this->assertFalse(provisioning::account());
        $this->assertFalse($DB->record_exists('user', ['username' => provisioning::USERNAME]));
        $this->assertSame(0, $DB->count_records('external_services_users', ['externalserviceid' => $service->id]));
    }

    public function test_ensure_role_resets_capabilities_and_respects_a_foreign_role(): void {
        global $DB;
        $this->resetAfterTest();
        $roleid = (int) get_config('local_miwlab', 'roleid');
        $system = \context_system::instance();
        assign_capability('moodle/site:config', CAP_ALLOW, $roleid, $system->id, true);
        unassign_capability('local/miwlab:viewmoduletypes', $roleid, $system->id);
        $this->assertSame($roleid, provisioning::ensure_role());
        $given = $DB->get_fieldset_select('role_capabilities', 'capability', 'roleid = ?', [$roleid]);
        $this->assertEqualsCanonicalizing(provisioning::capabilities(), $given);
        // A role with the same short name that the plugin did not create is never touched.
        delete_role($roleid);
        unset_config('roleid', 'local_miwlab');
        $other = create_role('Outro', provisioning::ROLE, '');
        $this->assertSame(0, provisioning::ensure_role());
        $this->assertSame(0, $DB->count_records('role_capabilities', ['roleid' => $other]));
    }

    public function test_generate_key_makes_a_working_token(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enablewebservices', 0);
        set_config('webserviceprotocols', '');
        // Web service authentication off, another authentication method on (it has to stay on).
        set_config('auth', 'email');
        $token = provisioning::generate_key();
        // No debugging message (Moodle 5.3 deprecated user_create_user()).
        $this->assertDebuggingNotCalled();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $token);
        // Moodle 5.3 refuses the token of an account whose authentication method is disabled (wsaccessusernologin).
        $this->assertTrue(is_enabled_auth('webservice'));
        $this->assertTrue(is_enabled_auth('email'));
        $this->assertSame('1', (string) get_config('core', 'enablewebservices'));
        $this->assertContains('rest', explode(',', $CFG->webserviceprotocols));
        $account = provisioning::account();
        $this->assertSame(provisioning::USERNAME, $account->username);
        $this->assertSame('webservice', $account->auth);
        $this->assertSame(provisioning::EMAIL, $account->email);
        $service = provisioning::service();
        $authorised = ['externalserviceid' => $service->id, 'userid' => $account->id];
        $this->assertTrue($DB->record_exists('external_services_users', $authorised));
        $roleid = (int) get_config('local_miwlab', 'roleid');
        $this->assertTrue(user_has_role_assignment($account->id, $roleid, \context_system::instance()->id));
        $row = $DB->get_record('external_tokens', ['userid' => $account->id, 'externalserviceid' => $service->id], '*', MUST_EXIST);
        $this->assertSame((string) EXTERNAL_TOKEN_PERMANENT, (string) $row->tokentype);
        // The account can call what the MiwLab calls, and nothing it does not need.
        accesslib_clear_all_caches_for_unit_testing();
        $system = \context_system::instance();
        foreach (provisioning::capabilities() as $capability) {
            $this->assertTrue(has_capability($capability, $system, $account), $capability);
        }
        $this->assertFalse(has_capability('moodle/site:config', $system, $account));
        $this->assertFalse(has_capability('mod/feedback:viewreports', $system, $account));
        // Least privilege: the key reads every course but cannot change one by Moodle's own capabilities (the replacement of
        // the content, 1.5.0, goes only through the plugin, which checks local/miwlab:replacecourse and nobody's progress).
        $this->assertFalse(has_capability('moodle/course:update', $system, $account));
        $this->assertFalse(has_capability('moodle/course:changefullname', $system, $account));
        $this->assertFalse(has_capability('moodle/backup:userinfo', $system, $account));
        $this->assertTrue(has_capability('local/miwlab:replacecourse', $system, $account));
        $this->assertFalse(has_capability('moodle/course:manageactivities', $system, $account));
        if (class_exists(\core_auth\validate_user::class)) {
            // Moodle 5.3 on: the check the web service server runs before accepting the token.
            \core\di::get(\core_auth\validate_user::class)->validate_before_external_login($account);
        }
        $this->setUser($account);
        $types = external\get_module_types::execute();
        $types = external\base::clean_returnvalue(external\get_module_types::execute_returns(), $types);
        $this->assertNotEmpty($types['modules']);
    }

    public function test_second_key_reuses_the_account_and_revokes_the_first(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $first = provisioning::generate_key();
        $accountid = provisioning::account()->id;
        $second = provisioning::generate_key();
        $this->assertNotSame($first, $second);
        $this->assertSame($accountid, provisioning::account()->id);
        $this->assertSame(1, $DB->count_records('user', ['username' => provisioning::USERNAME, 'deleted' => 0]));
        $this->assertFalse($DB->record_exists('external_tokens', ['token' => $first]));
        $this->assertSame(1, $DB->count_records('external_services_users', ['userid' => $accountid]));
        $this->assertSame(1, provisioning::revoke_key());
        $this->assertSame(0, $DB->count_records('external_tokens', ['userid' => $accountid]));
        $this->assertSame($accountid, provisioning::account()->id);
    }

    public function test_upgrade_enables_web_service_authentication_only_for_an_existing_account(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('auth', '');
        // No account yet: the site configuration is not touched.
        $this->assertFalse(provisioning::enable_authentication());
        $this->assertFalse(is_enabled_auth('webservice'));
        // A key generated before 1.3.0, with the authentication off (the account stops working on Moodle 5.3).
        provisioning::generate_key();
        set_config('auth', '');
        $this->assertFalse(is_enabled_auth('webservice'));
        $this->assertTrue(provisioning::enable_authentication());
        $this->assertTrue(is_enabled_auth('webservice'));
    }

    public function test_never_takes_over_an_account_it_did_not_create(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $someone = $this->getDataGenerator()->create_user(['username' => provisioning::USERNAME]);
        try {
            provisioning::generate_key();
            $this->fail('An account of someone else was used');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:usernameinuse', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('external_tokens', ['userid' => $someone->id]));
        $this->assertFalse(user_has_role_assignment($someone->id, (int) get_config('local_miwlab', 'roleid')));
    }

    public function test_only_a_site_administrator_generates(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        provisioning::generate_key();
    }

    public function test_remove_and_uninstall_clean_up(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        provisioning::generate_key();
        $account = provisioning::account();
        $this->assertTrue(provisioning::remove_account());
        $this->assertSame('1', (string) $DB->get_field('user', 'deleted', ['id' => $account->id]));
        $this->assertSame(0, $DB->count_records('external_tokens', ['userid' => $account->id]));
        $this->assertFalse(provisioning::account());
        provisioning::generate_key();
        $again = provisioning::account();
        $roleid = (int) get_config('local_miwlab', 'roleid');
        provisioning::uninstall();
        $this->assertSame('1', (string) $DB->get_field('user', 'deleted', ['id' => $again->id]));
        $this->assertFalse($DB->record_exists('role', ['id' => $roleid]));
        $this->assertFalse(get_config('local_miwlab', 'roleid'));
    }

    /**
     * A service made by hand, with one function, an authorised user and a token, as the manual key instructions made it.
     *
     * @param string $name
     * @return array [service id, token]
     */
    private function manual_service(string $name): array {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $id = $DB->insert_record('external_services', (object) [
            'name' => $name, 'enabled' => 1, 'restrictedusers' => 1, 'component' => null, 'timecreated' => time(),
            'timemodified' => time(), 'shortname' => null, 'downloadfiles' => 0, 'uploadfiles' => 1,
        ]);
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $id, 'functionname' => 'core_webservice_get_site_info',
        ]);
        $DB->insert_record('external_services_users', (object) [
            'externalserviceid' => $id, 'userid' => $user->id, 'timecreated' => time(),
        ]);
        $token = md5((string) random_int(0, PHP_INT_MAX));
        $DB->insert_record('external_tokens', (object) [
            'token' => $token, 'tokentype' => EXTERNAL_TOKEN_PERMANENT, 'userid' => $user->id, 'externalserviceid' => $id,
            'contextid' => \context_system::instance()->id, 'creatorid' => $user->id, 'timecreated' => time(),
        ]);
        return [$id, $token];
    }

    public function test_service_name_is_the_one_of_services_php(): void {
        $services = [];
        $functions = [];
        include(__DIR__ . '/../db/services.php');
        $this->assertSame([provisioning::SERVICE_NAME], array_keys($services));
        $this->assertSame(provisioning::SERVICE, $services[provisioning::SERVICE_NAME]['shortname']);
        // The manual key instructions named the service made by hand "MiwLab" (issue 143).
        $this->assertNotSame(provisioning::OLD_SERVICE_NAME, provisioning::SERVICE_NAME);
    }

    public function test_a_manual_service_with_the_plugin_name_is_renamed_and_keeps_its_key(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $plugin = provisioning::service();
        $DB->delete_records('external_services_functions', ['externalserviceid' => $plugin->id]);
        $DB->delete_records('external_services', ['id' => $plugin->id]);
        [$manual, $token] = $this->manual_service(provisioning::SERVICE_NAME);
        // CONTROL: a manual service with another name (the "MiwLab" of the manual instructions) is never touched.
        [$other] = $this->manual_service(provisioning::OLD_SERVICE_NAME);
        provisioning::prepare_service();
        external_update_descriptions('local_miwlab');
        $this->assertSame(provisioning::SERVICE_NAME . ' (manual)', $DB->get_field('external_services', 'name', ['id' => $manual]));
        $this->assertSame(provisioning::OLD_SERVICE_NAME, $DB->get_field('external_services', 'name', ['id' => $other]));
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $token, 'externalserviceid' => $manual]));
        $this->assertSame(1, $DB->count_records('external_services_functions', ['externalserviceid' => $manual]));
        $this->assertSame(1, $DB->count_records('external_services_users', ['externalserviceid' => $manual]));
        $this->assertNotFalse(provisioning::service());
        $this->assertSame(provisioning::SERVICE_NAME, provisioning::service()->name);
    }

    public function test_the_service_of_120_keeps_its_id_users_and_token(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/upgradelib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $token = provisioning::generate_key();
        $service = provisioning::service();
        // 1.2.0 named it "MiwLab".
        $DB->set_field('external_services', 'name', provisioning::OLD_SERVICE_NAME, ['id' => $service->id]);
        provisioning::prepare_service();
        external_update_descriptions('local_miwlab');
        $this->assertSame((int) $service->id, (int) provisioning::service()->id);
        $this->assertSame(provisioning::SERVICE_NAME, provisioning::service()->name);
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $token, 'externalserviceid' => $service->id]));
        $authorised = ['externalserviceid' => $service->id, 'userid' => provisioning::account()->id];
        $this->assertTrue($DB->record_exists('external_services_users', $authorised));
    }

    public function test_generate_key_creates_the_missing_service_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // An update that stopped half way (issue 143): the new version recorded, the service never created.
        $service = provisioning::service();
        $count = $DB->count_records('external_services_functions', ['externalserviceid' => $service->id]);
        $DB->delete_records('external_services_functions', ['externalserviceid' => $service->id]);
        $DB->delete_records('external_services', ['id' => $service->id]);
        $this->assertFalse(provisioning::service());
        $token = provisioning::generate_key();
        $again = provisioning::service();
        $this->assertNotFalse($again);
        $this->assertSame($count, $DB->count_records('external_services_functions', ['externalserviceid' => $again->id]));
        $this->assertTrue($DB->record_exists('external_tokens', ['token' => $token, 'externalserviceid' => $again->id]));
    }
}
