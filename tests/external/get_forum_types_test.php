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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_forum_types::class)]
/**
 * Tests of the type of each forum of a course.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_forum_types
 */
final class get_forum_types_test extends \advanced_testcase {
    /**
     * The forums the function returns, cleaned as the web service layer does, as cmid => type.
     *
     * @param int $courseid
     * @return array
     */
    protected function types(int $courseid): array {
        $result = base::clean_returnvalue(get_forum_types::execute_returns(), get_forum_types::execute($courseid));
        return array_column($result['forums'], 'type', 'cmid');
    }

    public function test_each_forum_comes_with_its_type(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $cms = [];
        foreach (['general', 'qanda', 'single', 'eachuser', 'blog'] as $type) {
            $forum = $generator->create_module('forum', ['course' => $course->id, 'type' => $type, 'name' => 'F ' . $type]);
            $cms[$type] = $forum->cmid;
        }
        // Not a forum: it does not come.
        $page = $generator->create_module('page', ['course' => $course->id]);
        $types = $this->types($course->id);
        foreach ($cms as $type => $cmid) {
            $this->assertSame($type, $types[$cmid]);
        }
        $this->assertCount(5, $types);
        $this->assertArrayNotHasKey($page->cmid, $types);
    }

    public function test_a_course_without_forums_and_a_forum_of_another_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $empty = $generator->create_course();
        $other = $generator->create_course();
        $generator->create_module('forum', ['course' => $other->id, 'type' => 'qanda']);
        $this->assertSame([], $this->types($empty->id));
        $this->assertCount(1, $this->types($other->id));
    }

    public function test_the_site_course_and_a_missing_course_are_refused(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        get_forum_types::execute(SITEID);
    }

    public function test_refused_without_the_capability_to_read_reports(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->expectException(\required_capability_exception::class);
        get_forum_types::execute($course->id);
    }
}
