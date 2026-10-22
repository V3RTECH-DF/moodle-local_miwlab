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

use local_miwlab\external\replace_course;
use local_miwlab\external\get_restore_status;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\replacer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\badges::class)]
/**
 * Tests of the course badges when the content of a course is replaced (1.6.0): the badges of the old content go, the ones the
 * package brings arrive active, a badge already awarded refuses the replacement, and a failed replacement puts the old badges
 * back as they were.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\replacer
 * @covers     \local_miwlab\local\badges
 */
final class replace_badges_test extends \advanced_testcase {
    /**
     * A course badge with the overall criterion and the completion of the given activity.
     *
     * @param int $courseid
     * @param int $cmid
     * @param string $name
     * @param int $status
     * @return int
     */
    protected function badge(int $courseid, int $cmid, string $name, int $status): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        $now = time();
        $id = $DB->insert_record('badge', (object) [
            'name' => $name, 'description' => 'D', 'timecreated' => $now, 'timemodified' => $now,
            'usercreated' => get_admin()->id, 'usermodified' => get_admin()->id, 'issuername' => 'Organização',
            'issuerurl' => 'https://example.invalid/', 'type' => BADGE_TYPE_COURSE, 'courseid' => $courseid, 'message' => 'M',
            'messagesubject' => 'S', 'attachment' => 1, 'notification' => 0, 'status' => $status,
        ]);
        $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
            'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
        $crit = $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY,
            'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
        $DB->insert_record('badge_criteria_param', (object) ['critid' => $crit, 'name' => 'module_' . $cmid, 'value' => $cmid]);
        return $id;
    }

    /**
     * The old course: a page and an active badge on it, a student enrolled, nobody with progress.
     *
     * @param \core_course_category $category
     * @return \stdClass
     */
    protected function old_course(\core_course_category $category): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/badgeslib.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(
            ['fullname' => 'Curso antigo', 'category' => $category->id, 'enablecompletion' => 1]
        );
        $page = $generator->create_module(
            'page',
            ['course' => $course->id, 'name' => 'Velha', 'completion' => 2, 'completionview' => 1]
        );
        $this->badge($course->id, $page->cmid, 'Crachá antigo', BADGE_STATUS_ACTIVE);
        $generator->create_and_enrol($course, 'student');
        return $course;
    }

    /**
     * The package, like the MiwLab one (no user data, the badges in it): a course with a page and a badge on it, in the draft
     * area of the user. With $broken, the page loses its file: Moodle deletes the content and then fails.
     *
     * @param \stdClass $user
     * @param bool $broken
     * @return int The draft item id.
     */
    protected function package_in_draft(\stdClass $user, bool $broken = false): int {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $generator = $this->getDataGenerator();
        $origin = $generator->create_course(['fullname' => 'Origem', 'enablecompletion' => 1]);
        $page = $generator->create_module(
            'page',
            ['course' => $origin->id, 'name' => 'Leitura', 'completion' => 2, 'completionview' => 1]
        );
        $this->badge($origin->id, $page->cmid, 'Crachá novo', BADGE_STATUS_INACTIVE);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $origin->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        $bc->execute_plan();
        $backup = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $packer = get_file_packer('application/vnd.moodle.backup');
        $dir = make_request_directory();
        $backup->extract_to_pathname($packer, $dir);
        $index = file_get_contents($dir . '/moodle_backup.xml');
        $index = preg_replace('#(<level>root</level>\s*<name>users</name>\s*<value>)1(</value>)#', '${1}0${2}', $index, 1);
        file_put_contents($dir . '/moodle_backup.xml', $index);
        if ($broken) {
            foreach (glob($dir . '/activities/page_*/page.xml') as $path) {
                unlink($path);
            }
        }
        $list = [];
        $tree = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($tree) as $path) {
            $list[substr($path->getPathname(), strlen($dir) + 1)] = $path->getPathname();
        }
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($user->id)->id;
        $packer->archive_to_storage($list, $usercontext, 'user', 'draft', $draftitemid, '/', 'pacote.mbz', $user->id);
        return $draftitemid;
    }

    /**
     * An account with the capabilities of the replacement in the category.
     *
     * @param \core_course_category $category
     * @param string $without A capability the account is prohibited.
     * @return \stdClass
     */
    protected function account(\core_course_category $category, string $without = ''): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Substitui', 'substitui' . $without, '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $context = \context_coursecat::instance($category->id);
        foreach (array_merge(replacer::CAPABILITIES, ['moodle/course:view']) as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        if ($without !== '') {
            assign_capability($without, CAP_PROHIBIT, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * The badges of a course, name => status.
     *
     * @param int $courseid
     * @return array
     */
    protected function badges_of(int $courseid): array {
        global $DB;
        return array_map('intval', $DB->get_records_menu('badge', ['courseid' => $courseid], 'name', 'name, status'));
    }

    /**
     * Queues the replacement and runs it as cron would.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param int $draftitemid
     * @return array The status of the restore.
     */
    protected function replace(\stdClass $course, \stdClass $user, int $draftitemid): array {
        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->setUser($user);
        return get_restore_status::execute($queued['restoreid']);
    }

    public function test_the_old_badges_go_and_the_new_ones_arrive_active(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $user = $this->account($category);
        $status = $this->replace($course, $user, $this->package_in_draft($user));
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame(['Crachá novo' => BADGE_STATUS_ACTIVE], $this->badges_of($course->id));
        // Deleted, not archived as a site badge (Moodle's own deletion of the content would leave it there, one per replacement).
        $this->assertSame(0, $this->count_named('Crachá antigo'));
    }

    public function test_a_failed_replacement_puts_the_old_badges_back_as_they_were(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $user = $this->account($category);
        $status = $this->replace($course, $user, $this->package_in_draft($user, true));
        $this->assertSame('failed', $status['status']);
        $this->assertTrue($status['rolledback'], $status['errormessage']);
        // What the failed restore brought is not left archived as a site badge either.
        $this->assertSame(0, $this->count_archived('Crachá novo'));
        if ((int) $CFG->branch < 405) {
            // Up to Moodle 4.4 a backup without user data carries no badges (backup_root_task: the badges depend on the users),
            // so the rollback cannot bring them back; they are not left archived as site badges either.
            $this->assertSame([], $this->badges_of($course->id));
            $this->assertSame(0, $this->count_named('Crachá antigo'));
            return;
        }
        $this->assertSame(['Crachá antigo' => BADGE_STATUS_ACTIVE], $this->badges_of($course->id));
        $this->assertSame(1, $this->count_named('Crachá antigo'));
    }

    public function test_the_replacement_needs_the_capabilities_to_delete_and_to_activate_badges(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        foreach (['moodle/badges:deletebadge', 'moodle/badges:configurecriteria'] as $capability) {
            $user = $this->account($category, $capability);
            $draft = $this->package_in_draft($user);
            $this->setUser($user);
            try {
                replace_course::execute($course->id, $draft);
                $this->fail("Replaced without {$capability}.");
            } catch (\moodle_exception $e) {
                $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
                $this->assertStringContainsString($capability, $e->getMessage());
            }
            $this->setAdminUser();
        }
        $this->assertSame(['Crachá antigo' => BADGE_STATUS_ACTIVE], $this->badges_of($course->id));
    }

    public function test_a_failure_after_the_restore_leaves_no_badge_of_the_package_archived(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $user = $this->account($category);
        $seen = [];
        replacer::$afterplanhook = function (int $courseid) use (&$seen): void {
            $seen = $this->badges_of($courseid);
            throw new \moodle_exception('error:precheck', 'local_miwlab', '', 'simulada');
        };
        try {
            $status = $this->replace($course, $user, $this->package_in_draft($user));
        } finally {
            replacer::$afterplanhook = null;
        }
        // The package was restored, its badge in the course, when the failure came.
        $this->assertSame(['Crachá novo' => BADGE_STATUS_INACTIVE], $seen);
        $this->assertSame('failed', $status['status']);
        $this->assertTrue($status['rolledback'], $status['errormessage']);
        $this->assertSame(0, $this->count_archived('Crachá novo'));
        $this->assertSame(0, $this->count_named('Crachá novo', $course->id));
        if ((int) $CFG->branch >= 405) {
            $this->assertSame(['Crachá antigo' => BADGE_STATUS_ACTIVE], $this->badges_of($course->id));
        }
    }

    public function test_deleting_the_badges_of_a_course_refuses_an_awarded_one_and_deletes_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $id = array_key_first($this->badges_of_ids($course->id));
        $cmid = (int) $DB->get_field('course_modules', 'id', ['course' => $course->id]);
        $this->badge($course->id, $cmid, 'Outro', BADGE_STATUS_ACTIVE);
        (new \core_badges\badge($id))->issue($student->id, true);
        try {
            badges::remove_from_course($course->id);
            $this->fail('An awarded badge was deleted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:hasprogress', $e->errorcode);
        }
        $this->assertSame(
            ['Crachá antigo' => BADGE_STATUS_ACTIVE_LOCKED, 'Outro' => BADGE_STATUS_ACTIVE],
            $this->badges_of($course->id)
        );
        // Without an award, both go, for good, and what they were is returned in order.
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $other->id]);
        $this->badge($other->id, $page->cmid, 'B', BADGE_STATUS_INACTIVE);
        $this->badge($other->id, $page->cmid, 'A', BADGE_STATUS_ACTIVE);
        $this->assertSame(
            [['name' => 'B', 'status' => BADGE_STATUS_INACTIVE], ['name' => 'A', 'status' => BADGE_STATUS_ACTIVE]],
            badges::remove_from_course($other->id)
        );
        $this->assertSame([], $this->badges_of($other->id));
        $this->assertSame(0, $this->count_named('A') + $this->count_named('B'));
    }

    public function test_a_badge_awarded_while_the_replacement_waited_refuses_it_and_nothing_is_touched(): void {
        global $CFG;
        require_once($CFG->libdir . '/badgeslib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $user = $this->account($category);
        $this->setUser($user);
        $queued = replace_course::execute($course->id, $this->package_in_draft($user));
        $this->setAdminUser();
        $id = array_key_first($this->badges_of_ids($course->id));
        (new \core_badges\badge($id))->issue($student->id, true);
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->setUser($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertFalse($status['rolledback']);
        $this->assertSame(['Crachá antigo' => BADGE_STATUS_ACTIVE_LOCKED], $this->badges_of($course->id));
        $this->assertSame(1, $this->count_named('Crachá antigo'));
    }

    public function test_a_badge_awarded_in_the_course_refuses_the_replacement(): void {
        global $CFG;
        require_once($CFG->libdir . '/badgeslib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $course = $this->old_course($category);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $id = array_key_first($this->badges_of_ids($course->id));
        (new \core_badges\badge($id))->issue($student->id, true);
        $this->assertSame(1, replacer::people($course->id)['withprogress']);
        $user = $this->account($category);
        $this->setUser($user);
        $this->expectException(\moodle_exception::class);
        replace_course::execute($course->id, $this->package_in_draft($user));
    }

    /**
     * How many badges with a name there are on the whole site (archived site badges included), or in a course.
     *
     * @param string $name
     * @param int|null $courseid
     * @return int
     */
    protected function count_named(string $name, ?int $courseid = null): int {
        global $DB;
        $conditions = ['name' => $name];
        if ($courseid !== null) {
            $conditions['courseid'] = $courseid;
        }
        return $DB->count_records('badge', $conditions);
    }

    /**
     * How many badges with a name were archived as site badges (what Moodle does with the badges of a deleted course content).
     *
     * @param string $name
     * @return int
     */
    protected function count_archived(string $name): int {
        global $DB;
        return $DB->count_records('badge', ['name' => $name, 'courseid' => null, 'status' => BADGE_STATUS_ARCHIVED]);
    }

    /**
     * The ids of the badges of a course.
     *
     * @param int $courseid
     * @return array id => name
     */
    protected function badges_of_ids(int $courseid): array {
        global $DB;
        return $DB->get_records_menu('badge', ['courseid' => $courseid], 'id', 'id, name');
    }
}
