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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_info::class)]
/**
 * Tests of the detection of the plugin.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_info
 */
final class get_info_test extends \advanced_testcase {
    public function test_version_and_features(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $plugin = new \stdClass();
        require($CFG->dirroot . '/local/miwlab/version.php');
        $info = base::clean_returnvalue(get_info::execute_returns(), get_info::execute());
        $this->assertSame('local_miwlab', $info['component']);
        $this->assertSame($plugin->version, $info['version']);
        $this->assertSame($plugin->release, $info['release']);
        $this->assertSame(
            [
                'restore', 'restorestatus', 'findcourses', 'completion', 'feedback', 'moduletypes', 'courseformats', 'replace',
                'hidecourse', 'coursestate', 'blocks', 'badges', 'contentbackup', 'certificatetemplates', 'uploadlimits',
                'forumtopics',
            ],
            $info['features']
        );
        $this->assertFalse($info['systemcapabilities']['restorecourse']);
        $this->assertFalse($info['systemcapabilities']['viewreports']);
        $this->assertFalse($info['systemcapabilities']['viewmoduletypes']);
    }
}
