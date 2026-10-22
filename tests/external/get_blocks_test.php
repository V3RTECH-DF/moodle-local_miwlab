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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_blocks::class)]
/**
 * Tests of the list of the blocks installed on the site.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_blocks
 */
final class get_blocks_test extends \advanced_testcase {
    /**
     * An account with only local/miwlab:viewmoduletypes, in the given context (the site by default), enrolled nowhere.
     *
     * @param \context|null $context
     * @return \stdClass
     */
    protected function lister(?\context $context = null): \stdClass {
        $context = $context ?? \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Blocos', 'blocos', '');
        set_role_contextlevels($roleid, [$context->contextlevel]);
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * The blocks the function returns, cleaned as the web service layer does, by name.
     *
     * @return array
     */
    protected function blocks(): array {
        return array_column(base::clean_returnvalue(get_blocks::execute_returns(), get_blocks::execute())['blocks'], null, 'name');
    }

    public function test_lists_the_installed_blocks_with_their_names(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->lister());
        $blocks = $this->blocks();
        // Every installed block, and only installed ones.
        $this->assertEqualsCanonicalizing($DB->get_fieldset_select('block', 'name', '1 = 1'), array_keys($blocks));
        $this->assertSame(get_string('pluginname', 'block_html'), $blocks['html']['title']);
        $this->assertTrue($blocks['html']['visible']);
        $this->assertTrue($blocks['html']['standard']);
    }

    public function test_a_disabled_block_comes_as_not_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setUser($this->lister());
        $DB->set_field('block', 'visible', 0, ['name' => 'html']);
        \core_plugin_manager::reset_caches();
        $this->assertFalse($this->blocks()['html']['visible']);
        // The others are as they were.
        $this->assertTrue($this->blocks()['calendar_month']['visible']);
    }

    public function test_refused_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_blocks::execute();
    }

    public function test_refused_with_the_capability_only_in_a_category(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($this->lister(\context_coursecat::instance($category->id)));
        $this->expectException(\required_capability_exception::class);
        get_blocks::execute();
    }
}
