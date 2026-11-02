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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_scales::class)]
/**
 * Tests of the list of grading scales (issue 250).
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_scales
 */
final class get_scales_test extends \advanced_testcase {
    /**
     * An account with only the given capabilities, at site level.
     *
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function account(array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Escalas', 'escalas' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    public function test_lists_the_scales_of_the_site_with_their_items(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $generator->create_scale(['name' => 'Estrelas', 'scale' => 'Uma estrela, Duas estrelas ,Tres estrelas']);
        $course = $generator->create_course();
        $generator->create_scale(['name' => 'Da turma', 'scale' => 'Ruim,Bom', 'courseid' => $course->id]);
        $this->setUser($this->account(['local/miwlab:viewmoduletypes']));

        $site = base::clean_returnvalue(get_scales::execute_returns(), get_scales::execute());
        $byname = array_column($site['scales'], null, 'name');
        $this->assertSame(['Uma estrela', 'Duas estrelas', 'Tres estrelas'], $byname['Estrelas']['items']);
        $this->assertSame(3, $byname['Estrelas']['itemcount']);
        $this->assertSame(0, $byname['Estrelas']['courseid']);
        $this->assertArrayNotHasKey('Da turma', $byname, 'the scale of a course is not a scale of the site');
    }

    public function test_the_scales_of_a_course_come_when_the_course_is_asked_and_readable(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $generator->create_scale(['name' => 'Da turma', 'scale' => 'Ruim,Bom', 'courseid' => $course->id]);
        $this->setUser($this->account(['local/miwlab:viewmoduletypes', 'local/miwlab:viewreports']));
        $names = array_column(get_scales::execute((int) $course->id)['scales'], 'name');
        $this->assertContains('Da turma', $names);
        // Without the right to read the course, its scales are not given (the control: the site ones still are).
        $this->setUser($this->account(['local/miwlab:viewmoduletypes']));
        $this->assertNotEmpty(get_scales::execute()['scales'] ?? [], 'the site scales need only viewmoduletypes');
        $this->expectException(\required_capability_exception::class);
        get_scales::execute((int) $course->id);
    }

    public function test_needs_the_permission(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_scales::execute();
    }
}
