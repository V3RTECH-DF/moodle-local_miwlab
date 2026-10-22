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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_module_types::class)]
/**
 * Tests of the list of the module types installed on the site.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_module_types
 */
final class get_module_types_test extends \advanced_testcase {
    /**
     * An account with only local/miwlab:viewmoduletypes, at site level, enrolled nowhere.
     *
     * @return \stdClass
     */
    protected function lister(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Tipos', 'tipos', '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        $system = \context_system::instance();
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $system->id, true);
        role_assign($roleid, $user->id, $system->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    public function test_lists_installed_modules_without_a_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->lister());
        $DB->set_field('modules', 'visible', 0, ['name' => 'wiki']);
        \core_plugin_manager::reset_caches();
        $result = base::clean_returnvalue(get_module_types::execute_returns(), get_module_types::execute());
        $byname = array_column($result['modules'], null, 'name');
        $this->assertArrayHasKey('assign', $byname);
        $this->assertTrue($byname['assign']['enabled']);
        $this->assertTrue($byname['assign']['standard']);
        $this->assertSame(get_string('modulename', 'mod_assign'), $byname['assign']['title']);
        $this->assertGreaterThan(0, $byname['assign']['version']);
        // A module turned off in Manage activities still comes, marked as not enabled.
        $this->assertArrayHasKey('wiki', $byname);
        $this->assertFalse($byname['wiki']['enabled']);
        // Every installed module comes, and nothing else.
        $this->assertEqualsCanonicalizing(array_keys(\core_component::get_plugin_list('mod')), array_keys($byname));
    }

    public function test_refused_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_module_types::execute();
    }
}
