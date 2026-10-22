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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_course_formats::class)]
/**
 * Tests of the list of the course formats enabled on the site.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_course_formats
 */
final class get_course_formats_test extends \advanced_testcase {
    /**
     * An account with only local/miwlab:viewmoduletypes, in the given context (the site by default), enrolled nowhere.
     *
     * @param \context|null $context
     * @return \stdClass
     */
    protected function lister(?\context $context = null): \stdClass {
        $context = $context ?? \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Formatos', 'formatos', '');
        set_role_contextlevels($roleid, [$context->contextlevel]);
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * The formats the function returns, cleaned as the web service layer does.
     *
     * @return array
     */
    protected function formats(): array {
        return base::clean_returnvalue(get_course_formats::execute_returns(), get_course_formats::execute())['formats'];
    }

    public function test_lists_the_enabled_formats_as_the_course_form(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setUser($this->lister());
        $formats = $this->formats();
        $byname = array_column($formats, null, 'name');
        // The same formats, in the same order, as the "Course format" field of the course settings form.
        $this->assertSame(get_sorted_course_formats(true), array_column($formats, 'name'));
        $this->assertArrayHasKey('topics', $byname);
        $this->assertArrayHasKey('weeks', $byname);
        $this->assertSame(get_string('pluginname', 'format_weeks'), $byname['weeks']['title']);
        $this->assertTrue($byname['weeks']['standard']);
        // The default format of new courses is marked, and only it.
        $default = get_config('moodlecourse', 'format');
        $this->assertSame([$default], array_keys(array_filter(array_column($formats, 'default', 'name'))));
    }

    public function test_a_disabled_format_is_left_out(): void {
        $this->resetAfterTest();
        $this->setUser($this->lister());
        // Social comes enabled up to Moodle 5.0 and disabled from 5.1 on: it is turned on first, so the test starts from the
        // same place.
        \core\plugininfo\format::enable_plugin('social', 1);
        \core_plugin_manager::reset_caches();
        $this->assertContains('social', array_column($this->formats(), 'name'));
        \core\plugininfo\format::enable_plugin('social', 0);
        \core_plugin_manager::reset_caches();
        $names = array_column($this->formats(), 'name');
        $this->assertNotContains('social', $names);
        $this->assertContains('weeks', $names);
    }

    public function test_follows_the_order_and_the_default_of_the_site(): void {
        $this->resetAfterTest();
        $this->setUser($this->lister());
        set_config('format_plugins_sortorder', 'weeks,topics,social,singleactivity');
        set_config('format', 'weeks', 'moodlecourse');
        $formats = $this->formats();
        $this->assertSame(['weeks', 'topics'], array_slice(array_column($formats, 'name'), 0, 2));
        $this->assertSame(['weeks'], array_keys(array_filter(array_column($formats, 'default', 'name'))));
    }

    public function test_refused_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_course_formats::execute();
    }

    public function test_refused_with_the_capability_only_in_a_category(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $this->setUser($this->lister(\context_coursecat::instance($category->id)));
        $this->expectException(\required_capability_exception::class);
        get_course_formats::execute();
    }
}
