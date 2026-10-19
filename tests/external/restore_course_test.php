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

use local_miwlab\local\restorer;

/**
 * Tests of the restore of a package as a new course, in the background, and of its status.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\restore_course
 * @covers     \local_miwlab\external\get_restore_status
 * @covers     \local_miwlab\external\get_restore_categories
 * @covers     \local_miwlab\local\restorer
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\restore_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_restore_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_restore_categories::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\restorer::class)]
final class restore_course_test extends \advanced_testcase {
    /**
     * A user with a role, given in the category, holding the capabilities asked.
     *
     * @param \core_course_category $category
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function user_in_category(\core_course_category $category, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Restore ' . $user->id, 'restore' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $context = \context_coursecat::instance($category->id);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A backup of a small course, copied to the draft area of the user.
     *
     * @param \stdClass $user
     * @param string $idnumber ID number of the course backed up.
     * @return array [draft item id, the course backed up]
     */
    protected function package_in_draft(\stdClass $user, string $idnumber = ''): array {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Origem', 'idnumber' => $idnumber, 'enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Leitura']);
        $generator->create_module('forum', ['course' => $course->id, 'name' => 'Conversa']);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        $bc->execute_plan();
        $backup = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'pacote.mbz',
        ], $backup);
        return [$draftitemid, $course];
    }

    /**
     * Runs the queued restore as the background task would, as the user who queued it.
     *
     * @param \stdClass $user
     */
    protected function run_queued(\stdClass $user): void {
        $tasks = \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class);
        $this->assertCount(1, $tasks);
        $this->assertEquals($user->id, reset($tasks)->get_userid());
        // As cron runs it: as the user who queued it.
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
        $this->setUser($user);
    }

    public function test_restore_creates_the_course_in_the_category(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        [$draftitemid] = $this->package_in_draft($user);

        $this->setUser($user);
        $queued = base::clean_returnvalue(
            restore_course::execute_returns(),
            restore_course::execute($category->id, $draftitemid, '', 'miwlab:abc-123:v2')
        );
        $this->assertSame('queued', $queued['status']);
        $this->assertSame(0, $queued['courseid']);

        $this->run_queued($user);
        $status = base::clean_returnvalue(
            get_restore_status::execute_returns(),
            get_restore_status::execute($queued['restoreid'])
        );
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertEquals(1.0, $status['progress']);
        $course = $DB->get_record('course', ['id' => $status['courseid']], '*', MUST_EXIST);
        $this->assertEquals($category->id, $course->category);
        // The course backed up still exists on this site, so Moodle names the restored one "Origem copy 1".
        $this->assertStringStartsWith('Origem', $course->fullname);
        $this->assertSame('miwlab:abc-123:v2', $course->idnumber);
        $this->assertSame('miwlab:abc-123:v2', $status['marker']);
        $this->assertStringEndsWith('/course/view.php?id=' . $course->id, $status['courseurl']);
        $this->assertEquals(2, $DB->count_records('course_modules', ['course' => $course->id]));
        // The restore does not leave the account as a teacher of the course, and the package leaves the draft area.
        $this->assertFalse(is_enrolled(\context_course::instance($course->id), $user->id));
        $this->assertEquals(0, $DB->count_records_select(
            'role_assignments',
            'userid = :u AND contextid = :c',
            ['u' => $user->id, 'c' => \context_course::instance($course->id)->id]
        ));
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_user::instance($user->id)->id,
            'user',
            'draft',
            $draftitemid,
            'id',
            false
        ));
    }

    public function test_marker_in_the_package_is_kept_and_a_repeated_one_gets_the_course_id(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        // The course backed up keeps its ID number, so the marker the package carries is taken: it gets the id of the new course.
        [$draftitemid, $origin] = $this->package_in_draft($user, 'miwlab:origem-1:v1');

        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertNotEquals($origin->id, $status['courseid']);
        $this->assertSame(
            'miwlab:origem-1:v1:' . $status['courseid'],
            $DB->get_field('course', 'idnumber', ['id' => $status['courseid']])
        );
    }

    public function test_refusal_names_every_missing_capability(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $partial = $this->user_in_category($category, ['local/miwlab:restorecourse', 'moodle/restore:restorecourse']);
        $none = $this->getDataGenerator()->create_user();
        $full = $this->user_in_category($category, restorer::CAPABILITIES);

        $this->setUser($partial);
        try {
            restore_course::execute($category->id, 1);
            $this->fail('The restore should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcapabilities', $e->errorcode);
            $this->assertStringContainsString('moodle/course:create', $e->getMessage());
            $this->assertStringNotContainsString('moodle/restore:restorecourse', $e->getMessage());
        }

        $this->setUser($none);
        try {
            restore_course::execute($category->id, 1);
            $this->fail('The restore should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcapabilities', $e->errorcode);
            $this->assertStringContainsString(
                'local/miwlab:restorecourse, moodle/restore:restorecourse, moodle/course:create',
                $e->getMessage()
            );
        }

        // With all of them, the refusal is about the missing file, not about capabilities.
        $this->setUser($full);
        try {
            restore_course::execute($category->id, 1);
            $this->fail('The restore should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:draftfilenotfound', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
    }

    public function test_categories_list_only_where_every_capability_is_held(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $one = $this->getDataGenerator()->create_category(['name' => 'Uma']);
        $two = $this->getDataGenerator()->create_category(['name' => 'Outra']);
        $user = $this->user_in_category($one, restorer::CAPABILITIES);
        $context = \context_coursecat::instance($two->id);
        $roleid = create_role('Parcial', 'parcial', '');
        assign_capability('local/miwlab:restorecourse', CAP_ALLOW, $roleid, $context->id, true);
        role_assign($roleid, $user->id, $context->id);

        $this->setUser($user);
        $result = base::clean_returnvalue(get_restore_categories::execute_returns(), get_restore_categories::execute());
        $this->assertSame([(int) $one->id], array_column($result['categories'], 'id'));
    }

    public function test_a_file_that_is_not_a_backup_fails_with_the_reason_and_no_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'pacote.mbz',
        ], 'isto não é um backup');
        $courses = $DB->count_records('course');

        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:notabackup', $status['errorcode']);
        $this->assertNotEmpty($status['errormessage']);
        $this->assertSame(0, $status['courseid']);
        $this->assertEquals($courses, $DB->count_records('course'));
    }

    public function test_the_task_reads_and_deletes_only_a_file_in_the_draft_area_of_its_user(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        $other = $this->getDataGenerator()->create_user();
        [$draftitemid] = $this->package_in_draft($user);
        [$otheritemid] = $this->package_in_draft($other);
        $otherfile = get_file_storage()->get_area_files(
            \context_user::instance($other->id)->id,
            'user',
            'draft',
            $otheritemid,
            'id',
            false
        );
        $otherfile = reset($otherfile);

        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);
        // The task is pointed at the package of someone else.
        $record = $DB->get_record('task_adhoc', ['classname' => '\\local_miwlab\\task\\restore_course'], '*', MUST_EXIST);
        $data = json_decode($record->customdata);
        $data->fileid = (int) $otherfile->get_id();
        $DB->set_field('task_adhoc', 'customdata', json_encode($data), ['id' => $record->id]);

        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:draftfilenotfound', $status['errorcode']);
        $this->assertTrue(get_file_storage()->file_exists_by_hash($otherfile->get_pathnamehash()));
    }

    public function test_status_needs_the_capability_in_the_category(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        [$draftitemid] = $this->package_in_draft($user);
        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_restore_status::execute($queued['restoreid']);
    }

    public function test_marker_format(): void {
        $this->assertTrue(restorer::valid_marker('miwlab:0b9a3a2e-6a41-4c55-9d1e-0c4f5f6a7b8c:v2'));
        $this->assertTrue(restorer::valid_marker('miwlab:curso'));
        $this->assertFalse(restorer::valid_marker('outro:curso'));
        $this->assertFalse(restorer::valid_marker('miwlab:'));
        $this->assertFalse(restorer::valid_marker('miwlab:a:b:c'));
        $this->assertFalse(restorer::valid_marker('miwlab:a b'));
        $this->assertFalse(restorer::valid_marker('miwlab:' . str_repeat('a', 61)));
    }
}
