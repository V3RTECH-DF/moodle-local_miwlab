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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_certificate_templates::class)]
/**
 * Tests of the list of the certificate templates of the site.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_certificate_templates
 */
final class get_certificate_templates_test extends \advanced_testcase {
    /**
     * An account with only local/miwlab:viewmoduletypes, in the given context (the site by default), enrolled nowhere.
     *
     * @param \context|null $context
     * @return \stdClass
     */
    protected function lister(?\context $context = null): \stdClass {
        $context = $context ?? \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Modelos', 'modelos', '');
        set_role_contextlevels($roleid, [$context->contextlevel]);
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * What the function returns, cleaned as the web service layer does.
     *
     * @return array
     */
    protected function answer(): array {
        return base::clean_returnvalue(get_certificate_templates::execute_returns(), get_certificate_templates::execute());
    }

    /**
     * Skips the test where the certificate tool is not installed (it is not part of Moodle).
     */
    protected function require_tool(): void {
        if (!class_exists(\tool_certificate\template::class) || !get_config('mod_coursecertificate', 'version')) {
            $this->markTestSkipped('tool_certificate and mod_coursecertificate are not installed.');
        }
    }

    /**
     * The generator of the certificate tool.
     *
     * @return \tool_certificate_generator
     */
    protected function generator(): \tool_certificate_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    public function test_lists_the_templates_with_where_each_one_belongs(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_tool();
        $category = $this->getDataGenerator()->create_category(['name' => 'Formação']);
        $this->generator()->create_template(['name' => 'Do site']);
        $this->generator()->create_template(['name' => 'Compartilhado', 'shared' => 1]);
        $categorycontext = \context_coursecat::instance($category->id);
        $this->generator()->create_template(['name' => 'Da categoria', 'contextid' => $categorycontext->id]);
        $this->setUser($this->lister());
        $answer = $this->answer();
        $this->assertTrue($answer['installed']);
        $this->assertTrue($answer['enabled']);
        $byname = array_column($answer['templates'], null, 'name');
        $this->assertCount(3, $byname);
        $this->assertSame(0, $byname['Do site']['categoryid']);
        $this->assertSame('', $byname['Do site']['categoryname']);
        $this->assertFalse($byname['Do site']['shared']);
        $this->assertTrue($byname['Compartilhado']['shared']);
        $this->assertSame((int) $category->id, $byname['Da categoria']['categoryid']);
        $this->assertSame('Formação', $byname['Da categoria']['categoryname']);
        // The id is the one the activity stores in its template field: the id of the row.
        $ids = array_map('intval', array_column($answer['templates'], 'id'));
        sort($ids);
        $stored = array_map('intval', array_keys($DB->get_records('tool_certificate_templates')));
        sort($stored);
        $this->assertSame($stored, $ids);
    }

    public function test_the_list_is_empty_when_there_is_no_template(): void {
        $this->resetAfterTest();
        $this->require_tool();
        $this->setUser($this->lister());
        $answer = $this->answer();
        $this->assertTrue($answer['installed']);
        $this->assertSame([], $answer['templates']);
    }

    public function test_it_says_not_installed_when_the_activity_is_missing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_tool();
        $this->generator()->create_template(['name' => 'Existe, mas a atividade não']);
        // Without the row of the activity in the modules table, the site has no Course certificate, whatever else is there.
        $DB->delete_records('modules', ['name' => 'coursecertificate']);
        $this->setUser($this->lister());
        $answer = $this->answer();
        $this->assertFalse($answer['installed']);
        $this->assertFalse($answer['enabled']);
        $this->assertSame([], $answer['templates']);
    }

    public function test_enabled_follows_manage_activities(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_tool();
        $DB->set_field('modules', 'visible', 0, ['name' => 'coursecertificate']);
        $this->setUser($this->lister());
        $answer = $this->answer();
        $this->assertTrue($answer['installed']);
        $this->assertFalse($answer['enabled']);
    }

    public function test_refused_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_certificate_templates::execute();
    }

    public function test_refused_with_the_capability_only_in_a_category(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($this->lister(\context_coursecat::instance($category->id)));
        $this->expectException(\required_capability_exception::class);
        get_certificate_templates::execute();
    }
}
