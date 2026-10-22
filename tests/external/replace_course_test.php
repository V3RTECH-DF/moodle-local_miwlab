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

use local_miwlab\local\replacer;
use local_miwlab\local\restorer;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_replace_check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\replace_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\hide_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_course_state::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\replacer::class)]
/**
 * Tests of the replacement of the content of a course (1.5.0): the counts, the refusal with progress on the server, the backup
 * before replacing (a failed backup replaces nothing), what the course keeps, the capabilities, and hiding a course.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_replace_check
 * @covers     \local_miwlab\external\replace_course
 * @covers     \local_miwlab\external\hide_course
 * @covers     \local_miwlab\external\get_course_state
 * @covers     \local_miwlab\local\replacer
 */
final class replace_course_test extends \advanced_testcase {
    /**
     * Tidies the hook of the backup after each test.
     */
    protected function tearDown(): void {
        replacer::$backuphook = null;
        replacer::$knownmoduleshook = null;
        parent::tearDown();
    }

    /**
     * A user with a role, given in the category, holding the capabilities asked.
     *
     * @param \core_course_category $category
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function user_in_category(\core_course_category $category, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Replace ' . $user->id, 'replace' . $user->id, '');
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
     * The course to be replaced: a page "Velha" and a forum, a summary, a tag, a student enrolled (no progress) and a backup a
     * teacher had put in the course backup area.
     *
     * @param \core_course_category $category
     * @param string $idnumber
     * @param string $shortname
     * @return array [course, student, forum]
     */
    protected function old_course(\core_course_category $category, string $idnumber = '', string $shortname = 'ANTIGO'): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course([
            'fullname' => 'Curso antigo',
            'shortname' => $shortname,
            'category' => $category->id,
            'idnumber' => $idnumber,
            'summary' => 'Resumo antigo',
            'enablecompletion' => 1,
            'visible' => 1,
            'tags' => ['formacao'],
        ]);
        $generator->create_module('page', ['course' => $course->id, 'name' => 'Velha']);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Forum antigo']);
        $student = $generator->create_and_enrol($course, 'student');
        get_file_storage()->create_file_from_string([
            'contextid' => \context_course::instance($course->id)->id,
            'component' => 'backup',
            'filearea' => 'course',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'da-professora.mbz',
        ], 'uma copia de seguranca anterior');
        return [$course, $student, $forum];
    }

    /**
     * A backup of a small course ("Origem": a page "Leitura" and a forum "Conversa"), copied to the draft area of the user.
     *
     * @param \stdClass $user
     * @return int The draft item id.
     */
    protected function package_in_draft(\stdClass $user): int {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $generator = $this->getDataGenerator();
        $origin = $generator->create_course(['fullname' => 'Origem', 'enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $origin->id, 'name' => 'Leitura']);
        $generator->create_module('forum', ['course' => $origin->id, 'name' => 'Conversa']);
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
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'pacote.mbz',
        ], $backup);
        return $draftitemid;
    }

    /**
     * Runs the queued task as cron would, as the user who queued it.
     *
     * @param \stdClass $user
     */
    protected function run_queued(\stdClass $user): void {
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
        $this->setUser($user);
    }

    /**
     * The names of the activities of a course.
     *
     * @param int $courseid
     * @return string[]
     */
    protected function activity_names(int $courseid): array {
        rebuild_course_cache($courseid, true);
        $names = [];
        foreach (get_fast_modinfo($courseid)->get_cms() as $cm) {
            $names[] = $cm->name;
        }
        sort($names);
        return $names;
    }

    /**
     * Gives progress of one kind to the student in the course.
     *
     * @param string $kind
     * @param \stdClass $course
     * @param \stdClass $student
     * @param \stdClass $forum
     */
    protected function progress(string $kind, \stdClass $course, \stdClass $student, \stdClass $forum): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/badgeslib.php');
        $generator = $this->getDataGenerator();
        switch ($kind) {
            case 'completion':
                $cm = get_coursemodule_from_instance('forum', $forum->id);
                $DB->insert_record('course_modules_completion', (object) [
                    'coursemoduleid' => $cm->id, 'userid' => $student->id, 'completionstate' => 1, 'timemodified' => time(),
                ]);
                break;
            case 'grade':
                $item = $generator->create_grade_item(['courseid' => $course->id, 'itemtype' => 'manual']);
                $generator->create_grade_grade(['itemid' => $item->id, 'userid' => $student->id, 'finalgrade' => 7]);
                break;
            case 'submission':
                $assign = $generator->create_module('assign', ['course' => $course->id]);
                $DB->insert_record('assign_submission', (object) [
                    'assignment' => $assign->id, 'userid' => $student->id, 'status' => 'submitted', 'timecreated' => time(),
                    'timemodified' => time(), 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0,
                ]);
                break;
            case 'assigngrade':
                // A grade in the assignment, not released to the gradebook (marking workflow): not in grade_grades.
                $assign = $generator->create_module('assign', ['course' => $course->id, 'markingworkflow' => 1]);
                $DB->insert_record('assign_grades', (object) [
                    'assignment' => $assign->id, 'userid' => $student->id, 'grade' => 6, 'grader' => get_admin()->id,
                    'timecreated' => time(), 'timemodified' => time(), 'attemptnumber' => 0,
                ]);
                break;
            case 'post':
                $generator->get_plugin_generator('mod_forum')->create_discussion([
                    'course' => $course->id, 'forum' => $forum->id, 'userid' => $student->id,
                ]);
                break;
            case 'coursegrade':
                // A grade on the course total (an override), not on an activity or a manual item.
                $courseitem = \grade_item::fetch_course_item($course->id);
                $DB->insert_record('grade_grades', (object) [
                    'itemid' => $courseitem->id, 'userid' => $student->id, 'finalgrade' => 80,
                    'overridden' => time(), 'timecreated' => time(), 'timemodified' => time(),
                ]);
                break;
            case 'badge':
                $badge = $generator->get_plugin_generator('core_badges')->create_badge(['courseid' => $course->id,
                    'type' => BADGE_TYPE_COURSE, 'status' => BADGE_STATUS_ACTIVE]);
                $badge->issue($student->id, true);
                break;
            case 'competency':
                $DB->insert_record('competency_usercompcourse', (object) ['userid' => $student->id, 'courseid' => $course->id,
                    'competencyid' => 1, 'proficiency' => 1, 'grade' => 1, 'timecreated' => time(), 'timemodified' => time(),
                    'usermodified' => get_admin()->id]);
                break;
            case 'note':
                $generator->get_plugin_generator('core_notes')->create_instance([
                    'courseid' => $course->id, 'userid' => $student->id,
                ]);
                break;
        }
    }

    /**
     * The kinds of progress that block the replacement.
     *
     * @return array
     */
    public static function progress_kinds(): array {
        return [
            ['completion'], ['grade'], ['coursegrade'], ['submission'], ['assigngrade'], ['post'], ['note'], ['badge'],
            ['competency'],
        ];
    }

    /**
     * Each kind of progress is counted, and the replacement is refused on the server, before anything is queued.
     *
     * @dataProvider progress_kinds
     * @param string $kind
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('progress_kinds')]
    public function test_progress_is_counted_and_refused_on_the_server(string $kind): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $this->getDataGenerator()->create_and_enrol($course, 'student');
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        $this->progress($kind, $course, $student, $forum);

        $this->setUser($user);
        $check = base::clean_returnvalue(get_replace_check::execute_returns(), get_replace_check::execute($course->id));
        $this->assertSame(2, $check['enrolled']);
        $this->assertSame(1, $check['withprogress']);
        $this->assertFalse($check['canreplace']);
        try {
            replace_course::execute($course->id, $draftitemid);
            $this->fail('The replacement should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:hasprogress', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
        $this->assertContains('Velha', $this->activity_names($course->id));
    }

    public function test_what_is_not_progress_does_not_block(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $cm = get_coursemodule_from_instance('forum', $forum->id);
        // Seen, and an activity not completed: not progress.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $cm->id, 'userid' => $student->id, 'completionstate' => 0, 'timemodified' => time(),
        ]);
        $DB->insert_record('course_modules_viewed', (object) ['coursemoduleid' => $cm->id, 'userid' => $student->id,
            'timecreated' => time()]);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);

        $this->setUser($user);
        $check = get_replace_check::execute($course->id);
        $this->assertSame(1, $check['enrolled']);
        $this->assertSame(0, $check['withprogress']);
        $this->assertTrue($check['canreplace']);
        $this->assertSame([], $check['missingcapabilities']);
    }

    public function test_replacement_keeps_the_course_and_makes_the_backup_first(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student] = $this->old_course($category);
        // As the "MiwLab" role: it reads any course (moodle/course:view), so the restore gives it a role there without
        // enrolling it.
        $user = $this->user_in_category($category, array_merge(replacer::CAPABILITIES, ['moodle/course:view']));
        $draftitemid = $this->package_in_draft($user);
        $context = \context_course::instance($course->id);

        $this->setUser($user);
        $queued = base::clean_returnvalue(
            replace_course::execute_returns(),
            replace_course::execute($course->id, $draftitemid, '', 'miwlab:curso-1:v2')
        );
        $this->assertSame('queued', $queued['status']);
        $this->assertSame('replace', $queued['mode']);
        $this->run_queued($user);
        $status = base::clean_returnvalue(get_restore_status::execute_returns(), get_restore_status::execute($queued['restoreid']));
        $this->assertSame('finished', $status['status'], $status['errormessage']);

        // The same course, with the new content.
        $this->assertSame((int) $course->id, $status['courseid']);
        $this->assertSame(['Conversa', 'Leitura'], $this->activity_names($course->id));
        $after = $DB->get_record('course', ['id' => $course->id], '*', MUST_EXIST);
        $this->assertSame('Curso antigo', $after->fullname);
        $this->assertSame('ANTIGO', $after->shortname);
        $this->assertEquals($category->id, $after->category);
        $this->assertSame('Resumo antigo', $after->summary);
        $this->assertEquals(1, $after->visible);
        $this->assertEquals($course->startdate, $after->startdate);
        $this->assertSame('miwlab:curso-1:v2', $after->idnumber);
        $this->assertSame(['formacao'], array_values(\core_tag_tag::get_item_tags_array('core', 'course', $course->id)));
        // The enrolment stays; the account is not left in the course.
        $this->assertTrue(is_enrolled($context, $student->id));
        $this->assertTrue(user_has_role_assignment(
            $student->id,
            $DB->get_field('role', 'id', ['shortname' => 'student']),
            $context->id
        ));
        $this->assertFalse(is_enrolled($context, $user->id));
        $this->assertEquals(0, $DB->count_records('role_assignments', ['userid' => $user->id, 'contextid' => $context->id]));
        // The backup of the old course is in the course backup area, with the backup that was already there.
        $this->assertStringStartsWith('miwlab-before-replace-course-' . $course->id . '-', $status['backupfilename']);
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context->id, 'backup', 'course', 0, '/', $status['backupfilename']));
        $this->assertTrue($fs->file_exists($context->id, 'backup', 'course', 0, '/', 'da-professora.mbz'));
        $this->assertCount(0, $fs->get_area_files(\context_user::instance($user->id)->id, 'user', 'backup', false, 'id', false));
        $this->assertCount(0, $fs->get_area_files($context->id, 'local_miwlab', replacer::STASH_AREA, false, 'id', false));

        // The backup restores the old content.
        $this->setAdminUser();
        $file = $fs->get_file($context->id, 'backup', 'course', 0, '/', $status['backupfilename']);
        $restored = $this->restore_as_new_course($file, $category->id);
        $this->assertSame(['Forum antigo', 'Velha'], $this->activity_names($restored));
    }

    /**
     * Restores a backup file as a new course, as admin.
     *
     * @param \stored_file $file
     * @param int $categoryid
     * @return int The new course.
     */
    protected function restore_as_new_course(\stored_file $file, int $categoryid): int {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $tempid = 'replacetest' . random_string(10);
        $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($tempid));
        $courseid = \restore_dbops::create_new_course('Copia', 'COPIA' . random_string(4), $categoryid);
        $rc = new \restore_controller(
            $tempid,
            $courseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id,
            \backup::TARGET_NEW_COURSE
        );
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();
        return $courseid;
    }

    public function test_a_failed_backup_replaces_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        replacer::$backuphook = function (): \stored_file {
            throw new \moodle_exception('error:backupempty', 'local_miwlab');
        };

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:backupfailed', $status['errorcode']);
        $this->assertFalse($status['rolledback']);
        $this->assertSame('', $status['backupfilename']);
        $this->assertSame(['Forum antigo', 'Velha'], $this->activity_names($course->id));
        $this->assertSame('Resumo antigo', $DB->get_field('course', 'summary', ['id' => $course->id]));
        $this->assertTrue(get_file_storage()->file_exists(
            \context_course::instance($course->id)->id,
            'backup',
            'course',
            0,
            '/',
            'da-professora.mbz'
        ));
    }

    public function test_progress_made_while_waiting_stops_the_replacement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->setAdminUser();
        $this->progress('post', $course, $student, $forum);
        // With progress already there when the task starts, it does not even make the backup.
        $backups = 0;
        replacer::$backuphook = function () use (&$backups): \stored_file {
            $backups++;
            throw new \moodle_exception('error:backupempty', 'local_miwlab');
        };
        $this->run_queued($user);
        $this->assertSame(0, $backups);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:hasprogress', $status['errorcode']);
        $this->assertSame(['Forum antigo', 'Velha'], $this->activity_names($course->id));
    }

    public function test_progress_made_during_the_backup_stops_the_replacement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        // The backup is made, and somebody posts while it runs.
        replacer::$backuphook = function (int $courseid) use ($course, $student, $forum, $user): \stored_file {
            $this->progress('post', $course, $student, $forum);
            return get_file_storage()->create_file_from_string([
                'contextid' => \context_user::instance($user->id)->id, 'component' => 'user', 'filearea' => 'backup',
                'itemid' => 0, 'filepath' => '/', 'filename' => 'copia.mbz',
            ], 'copia');
        };

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:hasprogress', $status['errorcode']);
        $this->assertContains('Velha', $this->activity_names($course->id));
        // Nothing changed: the backup made is not left behind.
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_user::instance($user->id)->id,
            'user',
            'backup',
            false,
            'id',
            false
        ));
    }

    public function test_a_restore_that_fails_after_deleting_puts_the_backup_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        // The package loses the file of one of its activities: Moodle deletes the content and then fails.
        $this->break_package($user, $draftitemid);

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:replacefailed', $status['errorcode'], $status['errormessage']);
        $this->assertTrue($status['rolledback']);
        $this->assertSame(['Forum antigo', 'Velha'], $this->activity_names($course->id));
        $this->assertSame('Resumo antigo', $DB->get_field('course', 'summary', ['id' => $course->id]));
        $context = \context_course::instance($course->id);
        $this->assertTrue(get_file_storage()->file_exists($context->id, 'backup', 'course', 0, '/', $status['backupfilename']));
        $this->assertTrue(get_file_storage()->file_exists($context->id, 'backup', 'course', 0, '/', 'da-professora.mbz'));
    }

    /**
     * 1.6.0 (issue 155): the package with the content of this same course (imported into the MiwLab and sent back) brings the
     * question bank back. The defect this guards (Moodle's precheck matching the package's question categories to the course's
     * own and the deletion of the content then leaving them out) shows on a real Moodle with the MiwLab conference
     * (integracoes/moodle/testes/conteudo-chave.sh); this test keeps the result, and did not reproduce the defect by itself.
     */
    public function test_the_question_bank_of_a_package_of_the_same_course_comes_back(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'fullname' => 'Com banco']);
        // The question bank of the course: the course context up to 4.5, a question bank of the course (mod_qbank) from 5.0 on.
        $helper = '\core_question\local\bank\question_bank_helper';
        if (class_exists($helper) && method_exists($helper, 'get_default_open_instance_system_type')) {
            $bank = \context_module::instance($helper::get_default_open_instance_system_type($course, true)->id);
        } else {
            $bank = \context_course::instance($course->id);
        }
        $qg = $generator->get_plugin_generator('core_question');
        $qcat = $qg->create_question_category(['contextid' => $bank->id, 'name' => 'Banco do curso']);
        $question = $qg->create_question('shortanswer', null, ['category' => $qcat->id, 'name' => 'Do banco']);
        // A question of the bank no activity uses (a course bank also keeps those).
        $qg->create_question('essay', null, ['category' => $qcat->id, 'name' => 'Solta']);
        $quiz = $generator->create_module('quiz', ['course' => $course->id, 'name' => 'Teste', 'sumgrades' => 0]);
        quiz_add_quiz_question($question->id, $quiz);
        // The package is the content of THIS course (the course imported into the MiwLab and sent back).
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        $bc->get_plan()->get_setting('users')->set_value(0);
        $bc->execute_plan();
        $backup = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $user = $this->user_in_category(
            $category,
            array_merge(replacer::CAPABILITIES, ['moodle/question:add', 'moodle/question:managecategory'])
        );
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_storedfile(['contextid' => \context_user::instance($user->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $draftitemid, 'filepath' => '/',
            'filename' => 'pacote.mbz'], $backup);

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        // The question is in a bank of the course again, and the quiz uses it.
        $contexts = $DB->get_fieldset_sql(
            'SELECT ctx.id FROM {context} ctx WHERE ctx.path LIKE ? OR ctx.id = ?',
            [\context_course::instance($course->id)->path . '/%', \context_course::instance($course->id)->id]
        );
        [$in, $params] = $DB->get_in_or_equal($contexts);
        $names = $DB->get_fieldset_sql("SELECT q.name FROM {question} q
            JOIN {question_versions} qv ON qv.questionid = q.id
            JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
           WHERE qc.contextid $in AND qc.name = 'Banco do curso'", $params);
        sort($names);
        $this->assertSame(['Do banco', 'Solta'], $names);
        $newquiz = $DB->get_record('quiz', ['course' => $course->id], '*', MUST_EXIST);
        $this->assertSame(1, $DB->count_records('quiz_slots', ['quizid' => $newquiz->id]));
        $this->assertSame(1, $DB->count_records_sql("SELECT COUNT(1) FROM {question_references} qr
            JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
            JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
           WHERE qr.component = 'mod_quiz' AND qr.questionarea = 'slot' AND qc.contextid $in", $params));
    }

    /**
     * Takes the module.xml of the page out of the package in the draft area.
     *
     * @param \stdClass $user
     * @param int $draftitemid
     */
    protected function break_package(\stdClass $user, int $draftitemid): void {
        $fs = get_file_storage();
        $usercontext = \context_user::instance($user->id);
        $files = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id', false);
        $file = reset($files);
        $dir = make_request_directory();
        $packer = get_file_packer('application/vnd.moodle.backup');
        $file->extract_to_pathname($packer, $dir);
        foreach (glob($dir . '/activities/page_*/page.xml') as $path) {
            unlink($path);
        }
        $list = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path) {
            $list[substr($path->getPathname(), strlen($dir) + 1)] = $path->getPathname();
        }
        $file->delete();
        $packer->archive_to_storage($list, $usercontext->id, 'user', 'draft', $draftitemid, '/', 'pacote.mbz');
    }

    public function test_an_id_number_of_the_organisation_is_kept(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category, 'SIS-2026-01');
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid, '', 'miwlab:curso-1:v2');
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame('SIS-2026-01', $DB->get_field('course', 'idnumber', ['id' => $course->id]));
        $this->assertSame('SIS-2026-01', $status['marker']);
    }

    public function test_capabilities_are_named_and_the_site_is_never_replaced(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $partial = $this->user_in_category($category, array_diff(replacer::CAPABILITIES, ['moodle/restore:configure']));
        $none = $this->getDataGenerator()->create_user();
        $full = $this->user_in_category($category, replacer::CAPABILITIES);

        $this->setUser($partial);
        try {
            replace_course::execute($course->id, 1);
            $this->fail('The replacement should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
            $this->assertStringContainsString('moodle/restore:configure', $e->getMessage());
            $this->assertStringNotContainsString('moodle/backup:backupcourse', $e->getMessage());
        }
        $check = get_replace_check::execute($course->id);
        $this->assertSame(['moodle/restore:configure'], $check['missingcapabilities']);
        $this->assertFalse($check['canreplace']);

        $this->setUser($none);
        try {
            get_replace_check::execute($course->id);
            $this->fail('The check should be refused');
        } catch (\required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
            $this->assertStringContainsString(get_capability_string('local/miwlab:replacecourse'), $e->getMessage());
        }

        $this->setUser($full);
        foreach ([SITEID, 999999] as $courseid) {
            try {
                replace_course::execute($courseid, 1);
                $this->fail('The replacement should be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:coursenotfound', $e->errorcode);
            }
        }
        // With every capability, the refusal is about the missing file.
        try {
            replace_course::execute($course->id, 1);
            $this->fail('The replacement should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:draftfilenotfound', $e->errorcode);
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
    }

    public function test_a_capability_in_another_category_does_not_reach_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $mine = $this->getDataGenerator()->create_category();
        $other = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($other);
        $user = $this->user_in_category($mine, replacer::CAPABILITIES);
        $this->setUser($user);
        $this->expectException(\required_capability_exception::class);
        get_replace_check::execute($course->id);
    }

    public function test_a_second_replacement_waits_for_the_first(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $first = $this->package_in_draft($user);
        $second = $this->package_in_draft($user);

        $this->setUser($user);
        replace_course::execute($course->id, $first);
        $this->assertTrue(get_replace_check::execute($course->id)['inprogress']);
        $this->expectExceptionMessage(get_string('error:replaceinprogress', 'local_miwlab'));
        replace_course::execute($course->id, $second);
    }

    public function test_status_of_a_replacement_needs_the_capability_in_the_course(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $restorer = $this->user_in_category($category, restorer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid);

        // Restoring in the category is not enough to follow the replacement of a course.
        $this->setUser($restorer);
        $this->expectException(\required_capability_exception::class);
        get_restore_status::execute($queued['restoreid']);
    }

    public function test_hide_needs_both_capabilities_and_hides(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $partial = $this->user_in_category($category, ['local/miwlab:hidecourse']);
        $full = $this->user_in_category($category, replacer::HIDE_CAPABILITIES);

        $this->setUser($partial);
        try {
            hide_course::execute($course->id);
            $this->fail('Hiding should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
            $this->assertStringContainsString('moodle/course:visibility', $e->getMessage());
        }
        $this->assertEquals(1, $DB->get_field('course', 'visible', ['id' => $course->id]));

        $this->setUser($full);
        $result = base::clean_returnvalue(hide_course::execute_returns(), hide_course::execute($course->id));
        $this->assertSame(['courseid' => (int) $course->id, 'visible' => false, 'changed' => true], $result);
        $this->assertEquals(0, $DB->get_field('course', 'visible', ['id' => $course->id]));
        $this->assertFalse(hide_course::execute($course->id)['changed']);
        $this->expectException(\moodle_exception::class);
        hide_course::execute(SITEID);
    }

    public function test_the_state_follows_edits_of_the_content_and_not_what_people_do(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $first = replacer::course_state($course->id);
        $this->assertSame($first, replacer::course_state($course->id));
        $this->assertSame(2, $first['activities']);
        // What people do is not a change of the content.
        $this->progress('post', $course, $student, $forum);
        $this->progress('completion', $course, $student, $forum);
        $this->assertSame($first['signature'], replacer::course_state($course->id)['signature']);
        // A section renamed, an activity added, an activity deleted: each changes it.
        $section = $DB->get_record('course_sections', ['course' => $course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->set_field('course_sections', 'name', 'Renomeada', ['id' => $section->id]);
        $renamed = replacer::course_state($course->id);
        $this->assertNotSame($first['signature'], $renamed['signature']);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id, 'name' => 'Nova']);
        $added = replacer::course_state($course->id);
        $this->assertNotSame($renamed['signature'], $added['signature']);
        // An activity edited (its content, saved later): the signature and the latest edit follow.
        $DB->set_field('page', 'timemodified', $added['contentmodified'] + 60, ['id' => $page->id]);
        $edited = replacer::course_state($course->id);
        $this->assertNotSame($added['signature'], $edited['signature']);
        $this->assertSame($added['contentmodified'] + 60, $edited['contentmodified']);
        $this->assertGreaterThanOrEqual($first['contentmodified'], $added['contentmodified']);
        // Deleted as the recycle bin and the course format leave it: the module row gone (course_delete_module is deprecated
        // in 5.2).
        $DB->delete_records('course_modules', ['id' => get_coursemodule_from_instance('page', $page->id)->id]);
        $this->assertNotSame($edited['signature'], replacer::course_state($course->id)['signature']);
    }

    public function test_a_course_that_changed_is_not_replaced(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        $state = replacer::course_state($course->id);

        $this->setUser($user);
        foreach ([[sha1('outra'), 0], ['', $state['contentmodified'] - 1]] as [$signature, $since]) {
            try {
                replace_course::execute($course->id, $draftitemid, '', '', $signature, $since);
                $this->fail('The replacement should be refused');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:coursechanged', $e->errorcode);
            }
        }
        $this->assertCount(0, \core\task\manager::get_adhoc_tasks(\local_miwlab\task\restore_course::class));
        // CONTROLE: the state the MiwLab knows, or no edit after the time given, is accepted.
        $queued = replace_course::execute($course->id, $draftitemid, '', '', '', $state['contentmodified']);
        $this->assertSame('queued', $queued['status']);
    }

    public function test_a_change_while_waiting_stops_the_replacement_and_the_state_after_is_returned(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $other = $this->old_course($category, '', 'OUTRO')[0];
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        $otheritemid = $this->package_in_draft($user);

        $this->setUser($user);
        $queued = replace_course::execute($course->id, $draftitemid, '', '', replacer::course_state($course->id)['signature']);
        $this->setAdminUser();
        $DB->set_field('course_sections', 'name', 'Mudou no Moodle', ['course' => $course->id, 'section' => 1]);
        $this->run_queued($user);
        $status = get_restore_status::execute($queued['restoreid']);
        $this->assertSame('failed', $status['status']);
        $this->assertSame('error:coursechanged', $status['errorcode']);
        $this->assertContains('Velha', $this->activity_names($course->id));

        // CONTROLE: unchanged, it is replaced, and the status brings the state right after, to be compared next time.
        $queued = replace_course::execute($other->id, $otheritemid, '', '', replacer::course_state($other->id)['signature']);
        $this->run_queued($user);
        $status = base::clean_returnvalue(get_restore_status::execute_returns(), get_restore_status::execute($queued['restoreid']));
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame(replacer::course_state($other->id)['signature'], $status['coursesignature']);
        $check = get_replace_check::execute($other->id);
        $this->assertSame($status['coursesignature'], $check['signature']);
    }

    public function test_the_state_needs_the_capability_to_read_reports(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $reader = $this->user_in_category($category, ['local/miwlab:viewreports']);
        $this->setUser($reader);
        $state = base::clean_returnvalue(get_course_state::execute_returns(), get_course_state::execute($course->id));
        $this->assertSame(replacer::course_state($course->id)['signature'], $state['signature']);
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        get_course_state::execute($course->id);
    }

    public function test_an_assessment_in_a_workshop_is_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $this->setUser($user);
        $this->assertSame(0, get_replace_check::execute($course->id)['withprogress']);
        $this->setAdminUser();
        $workshop = $this->getDataGenerator()->create_module('workshop', ['course' => $course->id]);
        $submission = $DB->insert_record('workshop_submissions', (object) ['workshopid' => $workshop->id, 'example' => 0,
            'authorid' => get_admin()->id, 'timecreated' => time(), 'timemodified' => time(), 'title' => 'T', 'content' => 'C']);
        $DB->insert_record('workshop_assessments', (object) ['submissionid' => $submission, 'reviewerid' => $student->id,
            'weight' => 1, 'timecreated' => time(), 'timemodified' => time()]);
        $this->setUser($user);
        // The admin as author and the student as reviewer: two people with progress.
        $this->assertSame(2, get_replace_check::execute($course->id)['withprogress']);
    }

    /**
     * A submission of a group (an assignment with team submission) is stored without a person (userid 0): it is progress of
     * each member of the group, and of the group even when it has no member any more (fail closed); a team submission that
     * is still new is not.
     */
    public function test_a_group_submission_is_progress_of_its_members_and_of_an_empty_group(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        [$course, $student] = $this->old_course($category);
        $other = $generator->create_and_enrol($course, 'student');
        $group = $generator->create_group(['courseid' => $course->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $student->id]);
        $generator->create_group_member(['groupid' => $group->id, 'userid' => $other->id]);
        $emptygroup = $generator->create_group(['courseid' => $course->id]);
        $assign = $generator->create_module('assign', ['course' => $course->id, 'teamsubmission' => 1]);
        $submission = function (int $groupid, string $status) use ($DB, $assign): int {
            return $DB->insert_record('assign_submission', (object) [
                'assignment' => $assign->id, 'userid' => 0, 'groupid' => $groupid, 'status' => $status, 'timecreated' => time(),
                'timemodified' => time(), 'attemptnumber' => 0, 'latest' => 1,
            ]);
        };
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $count = function () use ($user, $course): array {
            $this->setUser($user);
            $check = get_replace_check::execute($course->id);
            $this->setAdminUser();
            return [$check['withprogress'], $check['canreplace']];
        };

        // Control: the team submissions Moodle creates when somebody opens the assignment are new, not progress.
        $new = $submission($group->id, 'new');
        $submission($emptygroup->id, 'new');
        $submission(0, 'new');
        $this->assertSame([0, true], $count());

        // Submitted by the group: both members have progress.
        $DB->set_field('assign_submission', 'status', 'submitted', ['id' => $new]);
        $this->assertSame([2, false], $count());

        // Only a group without members, and only the default group (people in no group): still progress.
        $DB->delete_records('assign_submission', ['id' => $new]);
        $DB->set_field('assign_submission', 'status', 'draft', ['groupid' => $emptygroup->id]);
        $this->assertSame([1, false], $count());
        $DB->delete_records('assign_submission', ['groupid' => $emptygroup->id]);
        $DB->set_field('assign_submission', 'status', 'submitted', ['groupid' => 0]);
        $this->assertSame([1, false], $count());
    }

    /**
     * Records about people kept without the person (an anonymous answer): progress all the same (fail closed).
     */
    public function test_an_answer_kept_without_the_person_is_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $feedback = $this->getDataGenerator()->create_module('feedback', ['course' => $course->id, 'anonymous' => 1]);
        $DB->insert_record('feedback_completed', (object) ['feedback' => $feedback->id, 'userid' => 0, 'timemodified' => time(),
            'random_response' => 1, 'anonymous_response' => 1, 'courseid' => $course->id]);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $this->setUser($user);
        $check = get_replace_check::execute($course->id);
        $this->assertSame(1, $check['withprogress']);
        $this->assertFalse($check['canreplace']);
    }

    /**
     * Control: what the MiwLab account itself did in the course is not progress of a person.
     */
    public function test_what_the_miwlab_account_did_is_not_progress(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course, $student, $forum] = $this->old_course($category);
        $account = $this->getDataGenerator()->create_user();
        set_config('accountid', $account->id, 'local_miwlab');
        $this->progress('post', $course, $account, $forum);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $this->setUser($user);
        $this->assertSame(0, get_replace_check::execute($course->id)['withprogress']);
        $this->setAdminUser();
        $this->progress('post', $course, $student, $forum);
        $this->setUser($user);
        $this->assertSame(1, get_replace_check::execute($course->id)['withprogress']);
    }

    public function test_an_activity_of_a_module_the_plugin_cannot_read_refuses_the_replacement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        [$course] = $this->old_course($category);
        $user = $this->user_in_category($category, replacer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user);
        // Taken as a module the progress queries do not cover (fail closed): the forum of the course.
        replacer::$knownmoduleshook = array_values(array_diff(replacer::KNOWN_MODULES, ['forum']));

        $this->setUser($user);
        $check = get_replace_check::execute($course->id);
        $this->assertSame(['forum'], $check['unknownmodules']);
        $this->assertFalse($check['canreplace']);
        try {
            replace_course::execute($course->id, $draftitemid);
            $this->fail('The replacement should be refused');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:unknownmodules', $e->errorcode);
            $this->assertStringContainsString('forum', $e->getMessage());
        }
        // CONTROLE: every module of the course known, it is accepted.
        replacer::$knownmoduleshook = null;
        $this->assertSame([], get_replace_check::execute($course->id)['unknownmodules']);
        $this->assertSame('queued', replace_course::execute($course->id, $draftitemid)['status']);
    }

    public function test_every_module_of_this_moodle_with_data_of_people_is_known_or_refused(): void {
        global $DB;
        $this->resetAfterTest();
        // The modules installed here that are not in the list are refused, never passed silently.
        $installed = $DB->get_fieldset_select('modules', 'name', '1 = 1');
        $category = $this->getDataGenerator()->create_category();
        $course = $this->getDataGenerator()->create_course(['category' => $category->id]);
        foreach (array_diff($installed, replacer::KNOWN_MODULES) as $name) {
            $module = $DB->get_field('modules', 'id', ['name' => $name]);
            $DB->insert_record('course_modules', (object) [
                'course' => $course->id, 'module' => $module, 'instance' => 1, 'section' => 0, 'added' => time(),
            ]);
        }
        $unknown = replacer::unknown_modules($course->id);
        sort($installed);
        $expected = array_values(array_diff($installed, replacer::KNOWN_MODULES));
        sort($expected);
        $this->assertSame($expected, $unknown);
    }
}
