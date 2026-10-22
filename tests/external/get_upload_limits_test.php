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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_upload_limits::class)]
/**
 * Tests of the limits that can refuse the package sent by the web service upload.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_upload_limits
 */
final class get_upload_limits_test extends \advanced_testcase {
    /**
     * An account with only local/miwlab:viewmoduletypes at site level, enrolled nowhere.
     *
     * @return \stdClass
     */
    protected function lister(): \stdClass {
        $context = \context_system::instance();
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Limites', 'limites', '');
        set_role_contextlevels($roleid, [$context->contextlevel]);
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    public function test_the_site_maximum_is_the_one_in_force_when_it_is_the_smallest(): void {
        $limits = get_upload_limits::limits('64M', '128M', 10 * 1024 * 1024, 0);
        $this->assertSame(10 * 1024 * 1024, $limits['effective']);
        $this->assertSame('site', $limits['limitedby']);
        $this->assertSame(64 * 1024 * 1024, $limits['phpuploadmaxfilesize']);
        $this->assertSame(128 * 1024 * 1024, $limits['phppostmaxsize']);
        $this->assertSame(10 * 1024 * 1024, $limits['sitemaxbytes']);
    }

    public function test_the_php_upload_limit_is_the_one_in_force_when_it_is_the_smallest(): void {
        $limits = get_upload_limits::limits('2M', '8M', 100 * 1024 * 1024, 0);
        $this->assertSame(2 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phpupload', $limits['limitedby']);
    }

    public function test_the_php_post_limit_is_the_one_in_force_when_it_is_the_smallest(): void {
        $limits = get_upload_limits::limits('64M', '8M', 0, 0);
        $this->assertSame(8 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phppost', $limits['limitedby']);
    }

    public function test_a_site_maximum_of_zero_and_a_post_limit_of_zero_do_not_limit(): void {
        // A site maximum of 0 is "the PHP limit", and a post_max_size of 0 is unlimited: only the upload limit stays.
        $limits = get_upload_limits::limits('32M', '0', 0, 0);
        $this->assertSame(32 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phpupload', $limits['limitedby']);
        $this->assertSame(0, $limits['phppostmaxsize']);
        $this->assertSame(0, $limits['sitemaxbytes']);
    }

    public function test_an_empty_upload_limit_counts_as_five_megabytes_as_moodle_does(): void {
        $limits = get_upload_limits::limits('', '', 0, 0);
        $this->assertSame(5 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phpupload', $limits['limitedby']);
    }

    public function test_the_unit_suffixes_of_php_are_understood(): void {
        $limits = get_upload_limits::limits('2G', '1G', 0, 0);
        $this->assertSame(1024 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phppost', $limits['limitedby']);
        $this->assertSame(2 * 1024 * 1024 * 1024, $limits['phpuploadmaxfilesize']);
    }

    public function test_the_user_quota_is_reported_and_never_limits(): void {
        // The quota is for private files: a tiny quota does not change the limit in force for the upload.
        $limits = get_upload_limits::limits('64M', '64M', 0, 1024);
        $this->assertSame(1024, $limits['userquota']);
        $this->assertSame(64 * 1024 * 1024, $limits['effective']);
        $this->assertSame('phpupload', $limits['limitedby']);
    }

    public function test_the_limit_in_force_is_the_one_moodle_applies_to_the_upload(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->lister());
        // The site maximum below any PHP limit of this runner: the effective limit is the one webservice/upload.php computes.
        set_config('maxbytes', 1500);
        $answer = base::clean_returnvalue(get_upload_limits::execute_returns(), get_upload_limits::execute());
        $this->assertSame((int) get_max_upload_file_size($CFG->maxbytes), $answer['effective']);
        $this->assertSame(1500, $answer['effective']);
        $this->assertSame('site', $answer['limitedby']);
        $this->assertSame(1500, $answer['sitemaxbytes']);
        // And with no site maximum, the PHP limits decide.
        set_config('maxbytes', 0);
        $answer = base::clean_returnvalue(get_upload_limits::execute_returns(), get_upload_limits::execute());
        $this->assertSame((int) get_max_upload_file_size(0), $answer['effective']);
        $this->assertNotSame('site', $answer['limitedby']);
    }

    public function test_refused_without_the_capability(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_upload_limits::execute();
    }

    public function test_refused_with_the_capability_only_in_a_category(): void {
        $this->resetAfterTest();
        $category = $this->getDataGenerator()->create_category();
        $context = \context_coursecat::instance($category->id);
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Limites da categoria', 'limitescategoria', '');
        set_role_contextlevels($roleid, [$context->contextlevel]);
        assign_capability('local/miwlab:viewmoduletypes', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        get_upload_limits::execute();
    }
}
