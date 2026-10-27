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

use local_miwlab\external\apply_update;
use local_miwlab\external\preview_update;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\updater::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\update_runner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\update_classifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\update_operations::class)]
/**
 * Tests of the safe update of a course (1.9.0, issue 204): nothing a person did is lost, what would touch a grade or a
 * completion is
 * left out, the preview is what is applied, a failure in the middle puts everything back, and the course-wide items are a choice.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\updater
 * @covers     \local_miwlab\local\update_runner
 * @covers     \local_miwlab\local\update_classifier
 * @covers     \local_miwlab\local\update_operations
 */
final class update_test extends \advanced_testcase {
    /**
     * Tidies the hooks after each test.
     */
    protected function tearDown(): void {
        update_runner::$stephook = null;
        replacer::$backuphook = null;
        parent::tearDown();
    }

    /**
     * A course where people studied: a page (completion by hand, concluded by the student), an assignment with a submission and a
     * grade, a forum with a post, a feedback with an answer, an assignment nobody did, and a page only Moodle knows.
     *
     * @return \stdClass course, student and the activities by name.
     */
    protected function course_with_people(): \stdClass {
        global $DB, $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        $g = $this->getDataGenerator();
        $course = $g->create_course(['enablecompletion' => 1, 'numsections' => 3, 'fullname' => 'Curso vivo']);
        $student = $g->create_and_enrol($course, 'student');
        $other = $g->create_and_enrol($course, 'student');
        $a = new \stdClass();
        $a->course = $course;
        $a->student = $student;
        $a->other = $other;
        $a->page = $g->create_module('page', ['course' => $course->id, 'name' => 'Leitura', 'content' => '<p>antigo</p>',
            'completion' => 1, 'section' => 1]);
        $a->assign = $g->create_module('assign', ['course' => $course->id, 'name' => 'Tarefa', 'grade' => 100, 'section' => 1,
            'completion' => 2, 'completionsubmit' => 1, 'intro' => '<p>enunciado antigo</p>']);
        $a->forum = $g->create_module('forum', ['course' => $course->id, 'name' => 'Conversa', 'section' => 2]);
        $a->empty = $g->create_module('assign', ['course' => $course->id, 'name' => 'Tarefa vazia', 'grade' => 100, 'section' => 2,
            'completion' => 2, 'completionsubmit' => 1]);
        $a->only = $g->create_module('page', ['course' => $course->id, 'name' => 'So no Moodle', 'section' => 2]);
        $cm = fn($m) => get_coursemodule_from_instance($m, $a->{$m === 'page' ? 'page' : $m}->id);
        $a->cm = (object) [
            'page' => get_coursemodule_from_instance('page', $a->page->id),
            'assign' => get_coursemodule_from_instance('assign', $a->assign->id),
            'forum' => get_coursemodule_from_instance('forum', $a->forum->id),
            'empty' => get_coursemodule_from_instance('assign', $a->empty->id),
            'only' => get_coursemodule_from_instance('page', $a->only->id),
        ];
        $now = time();
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $a->cm->page->id, 'userid' => $student->id,
            'completionstate' => 1, 'viewed' => 1, 'timemodified' => $now]);
        $DB->insert_record('assign_submission', (object) ['assignment' => $a->assign->id, 'userid' => $student->id,
            'status' => 'submitted',
            'timecreated' => $now, 'timemodified' => $now, 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0]);
        $DB->insert_record('assign_grades', (object) ['assignment' => $a->assign->id, 'userid' => $student->id, 'grade' => 80,
            'grader' => get_admin()->id, 'timecreated' => $now, 'timemodified' => $now, 'attemptnumber' => 0]);
        $row = $DB->get_record('assign', ['id' => $a->assign->id]);
        $row->cmidnumber = '';
        assign_grade_item_update($row, ['userid' => $student->id, 'rawgrade' => 80]);
        $g->get_plugin_generator('mod_forum')->create_discussion(['course' => $course->id, 'forum' => $a->forum->id,
            'userid' => $student->id, 'message' => 'POSTAGEM-DO-ALUNO']);
        rebuild_course_cache($course->id, true);
        return $a;
    }

    /**
     * Everything the people did, as counts and values, to compare before and after.
     *
     * @param int $courseid
     * @return array
     */
    protected function fingerprint(int $courseid): array {
        global $DB;
        return [
            'completion' => $DB->get_records_sql_menu("SELECT cmc.id, cmc.completionstate FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid WHERE cm.course = ? ORDER BY cmc.id", [$courseid]),
            'grades' => $DB->get_records_sql_menu("SELECT gg.id, gg.finalgrade FROM {grade_grades} gg JOIN {grade_items} gi
                ON gi.id = gg.itemid WHERE gi.courseid = ? ORDER BY gg.id", [$courseid]),
            'submissions' => $DB->get_records_sql_menu("SELECT s.id, s.status FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment WHERE a.course = ? ORDER BY s.id", [$courseid]),
            'assigngrades' => $DB->get_records_sql_menu("SELECT g.id, g.grade FROM {assign_grades} g
                JOIN {assign} a ON a.id = g.assignment WHERE a.course = ? ORDER BY g.id", [$courseid]),
            'posts' => $DB->get_records_sql_menu("SELECT p.id, p.message FROM {forum_posts} p
                JOIN {forum_discussions} d ON d.id = p.discussion WHERE d.course = ? ORDER BY p.id", [$courseid]),
        ];
    }

    /**
     * The package of the new activities: a page in the section 1, a page in a new section 4, nothing else.
     *
     * @return \stored_file
     */
    protected function package(): \stored_file {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $g = $this->getDataGenerator();
        $src = $g->create_course(['numsections' => 4, 'fullname' => 'Origem']);
        $DB->set_field('course_sections', 'name', 'Secao nova quatro', ['course' => $src->id, 'section' => 4]);
        $g->create_module('page', ['course' => $src->id, 'name' => 'Pagina nova', 'section' => 1]);
        $g->create_module('page', ['course' => $src->id, 'name' => 'Pagina da secao nova', 'section' => 4]);
        rebuild_course_cache($src->id, true);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $src->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        $bc->execute_plan();
        $backup = $bc->get_results()['backup_destination'];
        $bc->destroy();
        return get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => file_get_unused_draft_itemid(), 'filepath' => '/', 'filename' => 'pacote.mbz',
        ], $backup);
    }

    /**
     * The empty list of operations, with the parts asked.
     *
     * @param array $parts
     * @return string
     */
    protected function ops(array $parts): string {
        return json_encode($parts + ['activities' => [], 'added' => [], 'retired' => [], 'sections' => [], 'newsections' => [],
            'retiredsections' => [], 'order' => [], 'sectionorder' => [], 'coursesettings' => new \stdClass()]);
    }

    /**
     * The item of a preview by id.
     *
     * @param array $preview
     * @param string $id
     * @return array|null
     */
    protected function item(array $preview, string $id): ?array {
        foreach ($preview['items'] as $i) {
            if ($i['id'] === $id) {
                return $i;
            }
        }
        return null;
    }

    /**
     * Queues and runs the application of a preview, as the task would.
     *
     * @param array $preview
     * @param bool $forcediscard
     * @return \stdClass The restore record after the run.
     */
    protected function apply(array $preview, bool $forcediscard = false): \stdClass {
        global $DB;
        $record = updater::queue(updater::open_preview($preview['previewid']), $forcediscard, 180);
        $record->status = restorer::STATUS_RUNNING;
        $DB->update_record('local_miwlab_restores', $record);
        update_runner::run($DB->get_record('local_miwlab_restores', ['id' => $record->id]));
        return $DB->get_record('local_miwlab_restores', ['id' => $record->id]);
    }

    public function test_an_id_that_is_not_of_the_course_is_refused_whole(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $foreign = $this->getDataGenerator()->create_course();
        $foreignpage = $this->getDataGenerator()->create_module('page', ['course' => $foreign->id]);
        $foreigncm = get_coursemodule_from_instance('page', $foreignpage->id);
        $foreignsection = \course_get_format($foreign)->get_section(1);
        $cases = [
            'activity' => ['activities' => [['cmid' => (int) $foreigncm->id, 'name' => 'x']]],
            'retired' => ['retired' => [['cmid' => (int) $foreigncm->id]]],
            'section' => ['sections' => [['id' => (int) $foreignsection->id, 'name' => 'x']]],
            'availability' => ['activities' => [['cmid' => (int) $a->cm->page->id, 'availability' =>
                json_encode(['op' => '&', 'c' => [['type' => 'completion', 'cm' => (int) $foreigncm->id, 'e' => 1]],
                    'showc' => [true]])]]],
            'order' => ['order' => [['section' => (int) \course_get_format($a->course)->get_section(1)->id,
                'items' => [(int) $foreigncm->id]]]],
        ];
        foreach ($cases as $name => $parts) {
            try {
                updater::preview($a->course, $this->ops($parts), '', null);
                $this->fail('the ' . $name . ' of another course was accepted');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:foreignid', $e->errorcode, $name);
            }
        }
        // The control: the same operations with ids of THIS course are accepted.
        $ok = updater::preview($a->course, $this->ops(['activities' => [['cmid' => (int) $a->cm->page->id,
            'name' => 'Leitura 2']]]), '', null);
        $this->assertNotNull($this->item($ok, 'a:' . $a->cm->page->id . ':name'));
        // A key that does not exist, and a field that is not known, are refused too.
        $this->expectException(\moodle_exception::class);
        updater::preview($a->course, $this->ops(['activities' => [['cmid' => (int) $a->cm->page->id, 'drop' => 1]]]), '', null);
    }

    public function test_progress_by_activity_is_the_progress_of_the_course_split_by_activity(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $progress = replacer::progress_by_activity((int) $a->course->id);
        $this->assertSame([(int) $a->student->id], $progress['activities'][(int) $a->cm->page->id]);
        $this->assertSame([(int) $a->student->id], array_values(array_unique($progress['activities'][(int) $a->cm->assign->id])));
        $this->assertSame([(int) $a->student->id], $progress['activities'][(int) $a->cm->forum->id]);
        $this->assertArrayNotHasKey((int) $a->cm->empty->id, $progress['activities']);
        $this->assertArrayNotHasKey((int) $a->cm->only->id, $progress['activities']);
        // The same people the course counts.
        $all = $progress['course'];
        foreach ($progress['activities'] as $users) {
            $all = array_merge($all, $users);
        }
        $this->assertCount(replacer::people((int) $a->course->id)['withprogress'], array_unique($all));
        // The total of the course (an override) is the course's, not an activity's.
        $item = \grade_item::fetch_course_item($a->course->id);
        global $DB;
        $DB->insert_record('grade_grades', (object) ['itemid' => $item->id, 'userid' => $a->other->id, 'finalgrade' => 70,
            'overridden' => time(), 'timecreated' => time(), 'timemodified' => time()]);
        $progress = replacer::progress_by_activity((int) $a->course->id);
        $this->assertEqualsCanonicalizing([(int) $a->student->id, (int) $a->other->id], $progress['course']);
    }

    public function test_what_would_touch_a_grade_or_a_completion_is_left_out_and_the_same_without_anyone_is_applied(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $manual = ['completion' => 1, 'completionview' => 0, 'completionexpected' => 0, 'completiongradeitemnumber' => null,
            'completionpassgrade' => 0, 'type' => ['completionsubmit' => 0]];
        $ops = ['activities' => [
            ['cmid' => (int) $a->cm->assign->id, 'grademax' => 50.0, 'completion' => $manual],
            ['cmid' => (int) $a->cm->empty->id, 'grademax' => 50.0, 'completion' => $manual],
        ]];
        $preview = updater::preview($a->course, $this->ops($ops), '', null);
        foreach (['grademax', 'completion'] as $field) {
            $with = $this->item($preview, 'a:' . $a->cm->assign->id . ':' . $field);
            $this->assertSame('skip', $with['action']);
            $this->assertSame('progress', $with['reason']);
            $this->assertSame(1, $with['people']);
            $without = $this->item($preview, 'a:' . $a->cm->empty->id . ':' . $field);
            $this->assertSame('update', $without['action'], 'the same change in the activity nobody did is applied');
        }
        $before = $this->fingerprint($a->course->id);
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertSame($before, $this->fingerprint($a->course->id));
        $this->assertEquals(100, $DB->get_field('assign', 'grade', ['id' => $a->assign->id]));
        $this->assertEquals(2, $DB->get_field('course_modules', 'completion', ['id' => $a->cm->assign->id]));
        $this->assertEquals(1, $DB->get_field('assign', 'completionsubmit', ['id' => $a->assign->id]));
        $this->assertEquals(50, $DB->get_field('assign', 'grade', ['id' => $a->empty->id]));
        $this->assertEquals(50, $DB->get_field('grade_items', 'grademax', ['courseid' => $a->course->id, 'itemmodule' => 'assign',
            'iteminstance' => $a->empty->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'completion', ['id' => $a->cm->empty->id]));
        $this->assertEquals(0, $DB->get_field('assign', 'completionsubmit', ['id' => $a->empty->id]));
        $result = json_decode($record->updateresult, true);
        $this->assertSame(2, $result['counts']['updated']);
        $this->assertSame(2, $result['counts']['skipped']);
    }

    public function test_the_safe_update_keeps_everything_the_people_did(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $s1 = (int) \course_get_format($a->course)->get_section(1)->id;
        $s2 = (int) \course_get_format($a->course)->get_section(2)->id;
        $ops = $this->ops([
            'activities' => [
                ['cmid' => (int) $a->cm->page->id, 'name' => 'Leitura nova', 'text' => ['field' => 'content',
                    'html' => '<p>texto novo</p>']],
                ['cmid' => (int) $a->cm->assign->id, 'name' => 'Tarefa nova', 'text' => ['field' => 'intro',
                    'html' => '<p>enunciado novo</p>']],
            ],
            'added' => [
                ['key' => 'p1', 'module' => 'page', 'name' => 'Pagina nova', 'section' => $s1],
                ['key' => 'p2', 'module' => 'page', 'name' => 'Pagina da secao nova', 'section' => 'ns1'],
            ],
            'newsections' => [['key' => 'ns1', 'number' => 4, 'name' => 'Secao nova quatro']],
            'retired' => [['cmid' => (int) $a->cm->forum->id]],
            'sections' => [['id' => $s2, 'name' => 'Segunda secao renomeada']],
            'order' => [['section' => $s1, 'items' => [(int) $a->cm->page->id, 'p1', (int) $a->cm->assign->id]]],
        ]);
        $preview = updater::preview($a->course, $ops, '', $this->package());
        $this->assertSame(6, $preview['counts']['update']);
        $this->assertSame(4, $preview['counts']['add']);
        $before = $this->fingerprint($a->course->id);
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertSame($before, $this->fingerprint($a->course->id), 'nothing a person did changed');
        $this->assertSame('texto novo', trim(strip_tags($DB->get_field('page', 'content', ['id' => $a->page->id]))));
        $this->assertSame('Leitura nova', $DB->get_field('page', 'name', ['id' => $a->page->id]));
        $this->assertSame('Tarefa nova', $DB->get_field('assign', 'name', ['id' => $a->assign->id]));
        $this->assertSame('Tarefa nova', $DB->get_field('grade_items', 'itemname', ['courseid' => $a->course->id,
            'itemmodule' => 'assign',
            'iteminstance' => $a->assign->id]));
        $this->assertStringContainsString('enunciado novo', $DB->get_field('assign', 'intro', ['id' => $a->assign->id]));
        // The new activity is in the right section and the right place.
        $modinfo = get_fast_modinfo($a->course->id);
        $sequence = array_map('intval', explode(',', $DB->get_field('course_sections', 'sequence', ['id' => $s1])));
        $new = null;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->name === 'Pagina nova') {
                $new = (int) $cm->id;
            }
        }
        $this->assertNotNull($new);
        $this->assertSame([(int) $a->cm->page->id, $new, (int) $a->cm->assign->id], $sequence);
        $newsection = $DB->get_record('course_sections', ['course' => $a->course->id, 'section' => 4]);
        $this->assertSame('Secao nova quatro', $newsection->name);
        $this->assertCount(1, array_filter(explode(',', $newsection->sequence)));
        // The retired one is hidden and still has its post; the one only Moodle knows is untouched.
        $this->assertEquals(0, $DB->get_field('course_modules', 'visible', ['id' => $a->cm->forum->id]));
        $this->assertSame('Segunda secao renomeada', $DB->get_field('course_sections', 'name', ['id' => $s2]));
        $this->assertSame('So no Moodle', $DB->get_field('page', 'name', ['id' => $a->only->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $a->cm->only->id]));
        // The state the MiwLab is told is the state of the course now.
        $this->assertSame(replacer::course_state((int) $a->course->id)['signature'], restorer::export($record)['coursesignature']);
        $this->assertNotEmpty($record->backupfilename);
        $this->assertEquals(0, $record->fullbackup);
    }

    public function test_a_change_of_the_course_after_the_preview_is_refused_and_an_activity_done_in_between_is_left_out(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $manual = ['completion' => 1, 'completionview' => 0, 'completionexpected' => 0, 'completiongradeitemnumber' => null,
            'completionpassgrade' => 0, 'type' => ['completionsubmit' => 0]];
        $ops = $this->ops(['activities' => [['cmid' => (int) $a->cm->empty->id, 'completion' => $manual, 'name' => 'Vazia nova']]]);
        // 1. The course changes in Moodle after the preview: refused, and a new preview is asked.
        $preview = updater::preview($a->course, $ops, '', null);
        set_coursemodule_visible($a->cm->only->id, 0);
        try {
            updater::queue(updater::open_preview($preview['previewid']), false, 180);
            $this->fail('applied on a course that changed');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:coursechanged', $e->errorcode);
        }
        $this->assertSame('Tarefa vazia', $DB->get_field('assign', 'name', ['id' => $a->empty->id]), 'nothing was applied');
        // 2. Somebody does the activity between the preview and the application: that item becomes "left out".
        $preview = updater::preview($a->course, $ops, '', null);
        $this->assertSame('update', $this->item($preview, 'a:' . $a->cm->empty->id . ':completion')['action']);
        $DB->insert_record('assign_submission', (object) ['assignment' => $a->empty->id, 'userid' => $a->other->id,
            'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'attemptnumber' => 0, 'latest' => 1, 'groupid' => 0]);
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $result = array_column(json_decode($record->updateresult, true)['items'], null, 'id');
        $this->assertSame('skipped', $result['a:' . $a->cm->empty->id . ':completion']['status']);
        $this->assertSame('progresschanged', $result['a:' . $a->cm->empty->id . ':completion']['reason']);
        $this->assertEquals(
            2,
            $DB->get_field('course_modules', 'completion', ['id' => $a->cm->empty->id]),
            'the completion rule stayed'
        );
        $this->assertSame(
            'Vazia nova',
            $DB->get_field('assign', 'name', ['id' => $a->empty->id]),
            'what was still safe was applied'
        );
    }

    public function test_the_text_of_a_label_changes_its_name_too(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $label = $this->getDataGenerator()->create_module('label', ['course' => $a->course->id, 'intro' => '<p>rotulo antigo</p>',
            'section' => 1]);
        $cm = get_coursemodule_from_instance('label', $label->id);
        rebuild_course_cache($a->course->id, true);
        $ops = ['activities' => [['cmid' => (int) $cm->id, 'text' => ['field' => 'intro', 'html' => '<p>rotulo novo</p>']]]];
        $preview = updater::preview($a->course, $this->ops($ops), '', null);
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $row = $DB->get_record('label', ['id' => $label->id]);
        $this->assertStringContainsString('rotulo novo', $row->intro);
        $this->assertStringContainsString('rotulo novo', $row->name, 'the name is derived from the new text');
        $this->assertStringNotContainsString('antigo', $row->name);
    }

    public function test_a_failure_in_the_middle_puts_everything_back(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $s1 = (int) \course_get_format($a->course)->get_section(1)->id;
        $ops = $this->ops([
            'activities' => [['cmid' => (int) $a->cm->page->id, 'name' => 'Leitura nova', 'text' => ['field' => 'content',
                'html' => '<p>novo</p>'],
                'visible' => false]],
            'added' => [['key' => 'p1', 'module' => 'page', 'name' => 'Pagina nova', 'section' => $s1],
                ['key' => 'p2', 'module' => 'page', 'name' => 'Pagina da secao nova', 'section' => 'ns1']],
            'newsections' => [['key' => 'ns1', 'number' => 4, 'name' => 'Secao nova quatro']],
            'retired' => [['cmid' => (int) $a->cm->forum->id]],
            'order' => [['section' => $s1, 'items' => ['p1', (int) $a->cm->page->id]]],
        ]);
        $preview = updater::preview($a->course, $ops, '', $this->package());
        $before = $this->fingerprint($a->course->id);
        $cms = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$a->course->id]);
        $sections = $DB->get_fieldset_select('course_sections', 'id', 'course = ?', [$a->course->id]);
        // It fails right after the retired forum was hidden: the restore happened, the texts changed.
        update_runner::$stephook = function (string $step) use ($s1): void {
            if ($step === 'order:' . $s1) {
                throw new \RuntimeException('failure in the middle');
            }
        };
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FAILED, (int) $record->status);
        $this->assertEquals(1, $record->rolledback, $record->errorcode . ' ' . $record->errormessage);
        $this->assertSame('error:updatefailed', $record->errorcode);
        $this->assertSame(
            $preview['signature'],
            replacer::course_state((int) $a->course->id)['signature'],
            'the signature is the one from before'
        );
        $this->assertSame($before, $this->fingerprint($a->course->id));
        $this->assertEqualsCanonicalizing($cms, $DB->get_fieldset_select(
            'course_modules',
            'id',
            'course = ?',
            [$a->course->id]
        ), 'what was added is gone');
        $this->assertEqualsCanonicalizing($sections, $DB->get_fieldset_select(
            'course_sections',
            'id',
            'course = ?',
            [$a->course->id]
        ));
        $this->assertSame('Leitura', $DB->get_field('page', 'name', ['id' => $a->page->id]));
        $this->assertSame('<p>antigo</p>', $DB->get_field('page', 'content', ['id' => $a->page->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $a->cm->page->id]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'visible', ['id' => $a->cm->forum->id]));
    }

    public function test_the_preview_is_of_one_account_one_course_and_one_use(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $ops = $this->ops(['activities' => [['cmid' => (int) $a->cm->page->id, 'name' => 'Outro nome']]]);
        $preview = updater::preview($a->course, $ops, '', null);
        // Another account does not see it.
        $this->setUser($this->getDataGenerator()->create_user());
        try {
            updater::open_preview($preview['previewid']);
            $this->fail('another account used the preview');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:previewnotfound', $e->errorcode);
        }
        $this->setAdminUser();
        $this->assertSame($preview['previewid'], updater::open_preview($preview['previewid'])->previewid);
        // Expired.
        $DB->set_field('local_miwlab_updates', 'timeexpires', time() - 1, ['previewid' => $preview['previewid']]);
        try {
            updater::open_preview($preview['previewid']);
            $this->fail('expired preview used');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:previewexpired', $e->errorcode);
        }
        // Used once.
        $preview = updater::preview($a->course, $ops, '', null);
        $this->apply($preview);
        try {
            updater::open_preview($preview['previewid']);
            $this->fail('applied twice');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:previewused', $e->errorcode);
        }
        // An unknown one.
        $this->expectException(\moodle_exception::class);
        updater::open_preview(str_repeat('a', 32));
    }

    public function test_the_capability_is_needed_to_preview_and_to_apply(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $category = \core_course_category::get($a->course->category);
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Atualiza', 'atualiza', '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $context = \context_coursecat::instance($category->id);
        // Everything but the plugin's own capability.
        foreach (array_diff(updater::CAPABILITIES, ['local/miwlab:updatecourse']) as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        try {
            preview_update::execute((int) $a->course->id, $this->ops([]));
            $this->fail('previewed without the capability');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
            $this->assertStringContainsString('local/miwlab:updatecourse', (string) $e->a->capabilities);
        }
        // With it, the same call answers, and the structure returned is the one declared.
        assign_capability('local/miwlab:updatecourse', CAP_ALLOW, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $result = preview_update::execute((int) $a->course->id, $this->ops(['activities' => [['cmid' => (int) $a->cm->page->id,
            'name' => 'Novo']]]));
        $clean = preview_update::clean_returnvalue(preview_update::execute_returns(), $result);
        $this->assertSame('a:' . $a->cm->page->id . ':name', $clean['items'][0]['id']);
        $this->assertNotEmpty($clean['previewid']);
        try {
            apply_update::execute(str_repeat('b', 32));
            $this->fail('applied an unknown preview');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:previewnotfound', $e->errorcode);
        }
    }

    /**
     * A course whose completion criterion is "the page concluded"; the student concluded it and the course (when asked).
     *
     * @param bool $concluded
     * @return \stdClass
     */
    protected function completion_course(bool $concluded): \stdClass {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_activity.php');
        set_config('enablecompletion', 1);
        $g = $this->getDataGenerator();
        $c = new \stdClass();
        $c->course = $g->create_course(['enablecompletion' => 1]);
        $c->prerequisite = $g->create_course(['enablecompletion' => 1]);
        $c->student = $g->create_and_enrol($c->course, 'student');
        $c->page = $g->create_module('page', ['course' => $c->course->id, 'name' => 'Leitura', 'completion' => 1]);
        $c->cm = get_coursemodule_from_instance('page', $c->page->id);
        $data = (object) ['id' => $c->course->id, 'criteria_activity' => [$c->cm->id => 1],
            'activity_aggregation' => COMPLETION_AGGREGATION_ALL,
            'overall_aggregation' => COMPLETION_AGGREGATION_ALL];
        $criterion = new \completion_criteria_activity();
        $criterion->update_config($data);
        if ($concluded) {
            $info = new \completion_info(get_course($c->course->id));
            $info->update_state(get_fast_modinfo($c->course->id)->get_cm($c->cm->id), COMPLETION_COMPLETE, $c->student->id);
            ob_start();
            (new \core\task\completion_regular_task())->execute();
            ob_end_clean();
        }
        return $c;
    }

    /**
     * The operations that replace the completion criteria by "concluded the prerequisite course".
     *
     * @param \stdClass $c
     * @param string $choice
     * @return string
     */
    protected function completion_ops(\stdClass $c, string $choice): string {
        return $this->ops(['coursesettings' => ['completion' => ['choice' => $choice, 'aggregation' => 1,
            'criteria' => [['type' => 7, 'courseinstance' => (int) $c->prerequisite->id]]]]]);
    }

    public function test_keeping_is_the_default_and_touches_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $c = $this->completion_course(true);
        $preview = updater::preview($c->course, $this->completion_ops($c, 'keep'), '', null);
        $item = $this->item($preview, 'cs:completion');
        $this->assertSame('keep', $item['action']);
        $this->assertSame('choice', $item['group']);
        $this->assertSame(1, $item['people'], 'the preview says how many would lose it');
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $c->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]));
        $this->assertSame(1, $DB->count_records_select(
            'course_completions',
            'course = ? AND timecompleted IS NOT NULL',
            [$c->course->id]
        ));
        $this->assertSame('kept', array_column(
            json_decode($record->updateresult, true)['items'],
            null,
            'id'
        )['cs:completion']['status']);
    }

    public function test_replacing_the_completion_criteria_of_a_course_nobody_concluded_loses_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $c = $this->completion_course(false);
        $preview = updater::preview($c->course, $this->completion_ops($c, 'replace'), '', null);
        $item = $this->item($preview, 'cs:completion');
        $this->assertSame('update', $item['action']);
        $this->assertSame(0, $item['people']);
        $this->assertFalse($item['needsdiscard']);
        // No confirmation is asked when nobody loses anything.
        $record = $this->apply($preview, false);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertSame(0, $DB->count_records('course_completion_criteria', ['course' => $c->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]));
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $c->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE,
            'courseinstance' => $c->prerequisite->id]));
        $this->assertEquals(0, $record->fullbackup, 'no full copy when nobody loses anything');
    }

    public function test_replacing_the_completion_criteria_somebody_concluded_needs_the_confirmation_and_the_full_copy(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $c = $this->completion_course(true);
        $this->assertSame(1, $DB->count_records_select(
            'course_completions',
            'course = ? AND timecompleted IS NOT NULL',
            [$c->course->id]
        ));
        $preview = updater::preview($c->course, $this->completion_ops($c, 'replace'), '', null);
        $item = $this->item($preview, 'cs:completion');
        $this->assertSame(1, $item['people']);
        $this->assertTrue($item['needsdiscard']);
        // Without the conscious confirmation it is refused and nothing is queued.
        try {
            updater::queue(updater::open_preview($preview['previewid']), false, 180);
            $this->fail('queued without the confirmation');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:discardnotconfirmed', $e->errorcode);
        }
        $this->assertSame(0, $DB->count_records('local_miwlab_restores', ['mode' => 'update']));
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $c->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY]));
        // With it: the full copy is made first, with the completion of the people, and then the criteria change.
        $record = $this->apply($preview, true);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertEquals(1, $record->fullbackup);
        $this->assertMatchesRegularExpression('~-full\.mbz$~', $record->backupfilename);
        $this->assertGreaterThan(time(), (int) $record->backupexpires);
        $file = get_file_storage()->get_file(
            \context_course::instance($c->course->id)->id,
            'backup',
            'course',
            0,
            '/',
            $record->backupfilename
        );
        $this->assertNotFalse($file);
        $dir = make_request_directory();
        $this->assertNotFalse($file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $dir));
        $this->assertFileExists($dir . '/users.xml');
        $this->assertStringContainsString($c->student->username, file_get_contents($dir . '/users.xml'));
        $this->assertMatchesRegularExpression('~<timecompleted>\d+</timecompleted>~', file_get_contents($dir . '/completion.xml'));
        $this->assertSame(0, $DB->count_records_select(
            'course_completions',
            'course = ? AND timecompleted IS NOT NULL',
            [$c->course->id]
        ));
        $this->assertSame(1, $DB->count_records('course_completion_criteria', ['course' => $c->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_COURSE]));
    }

    public function test_the_full_copy_needs_the_permission_to_back_up_user_data(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $c = $this->completion_course(true);
        $preview = updater::preview($c->course, $this->completion_ops($c, 'replace'), '', null);
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('SemUserinfo', 'semuserinfo', '');
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $context = \context_course::instance($c->course->id);
        foreach (updater::CAPABILITIES as $cap) {
            assign_capability($cap, CAP_ALLOW, $roleid, $context->id, true);
        }
        // The preview belongs to the account that made it: the same account, now without the permission to back up user data.
        global $DB;
        $DB->set_field('local_miwlab_updates', 'accountid', $user->id, ['previewid' => $preview['previewid']]);
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
        try {
            updater::queue(updater::open_preview($preview['previewid']), true, 180);
            $this->fail('queued without moodle/backup:userinfo');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
            $this->assertStringContainsString('moodle/backup:userinfo', (string) $e->a->capabilities);
        }
    }

    public function test_replacing_the_gradebook_keeps_the_grades_of_the_activities_and_counts_who_is_recalculated(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $course = $g->create_course();
        $students = [];
        for ($i = 1; $i <= 3; $i++) {
            $students[$i] = $g->create_and_enrol($course, 'student');
        }
        foreach ([1, 2] as $k) {
            $assign = $g->create_module('assign', ['course' => $course->id, 'name' => 'T' . $k, 'grade' => 100]);
            $row = $DB->get_record('assign', ['id' => $assign->id]);
            $row->cmidnumber = '';
            foreach ($students as $i => $s) {
                if ($i < 3 || $k === 1) {
                    assign_grade_item_update($row, ['userid' => $s->id, 'rawgrade' => 40 + 10 * $i + 5 * $k]);
                }
            }
        }
        $cat = \grade_category::fetch_course_category($course->id);
        $cat->aggregation = GRADE_AGGREGATE_WEIGHTED_MEAN;
        $cat->update();
        grade_regrade_final_grades($course->id);
        // The third student's total was typed by hand.
        $total = \grade_item::fetch_course_item($course->id);
        $gg = \grade_grade::fetch(['itemid' => $total->id, 'userid' => $students[3]->id]);
        $gg->set_overridden(true);
        $gg->finalgrade = 11.0;
        $gg->update();
        $grades = fn() => $DB->get_records_sql_menu(
            "SELECT gg.id, gg.finalgrade FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid
            WHERE gi.courseid = ? AND gi.itemtype = 'mod' ORDER BY gg.id",
            [$course->id]
        );
        $before = $grades();
        $ops = fn(string $choice) => $this->ops(['coursesettings' => ['gradebook' => ['choice' => $choice, 'category' =>
            ['aggregation' => GRADE_AGGREGATE_MEAN, 'aggregateonlygraded' => 1]]]]);
        $preview = updater::preview($course, $ops('replace'), '', null);
        $item = $this->item($preview, 'cs:gradebook');
        // Two people have a total that is recalculated; one has a total typed by hand, which stays.
        $this->assertSame(2, $item['people']);
        $this->assertSame(1, $item['kept']);
        $this->assertFalse($item['needsdiscard']);
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertEquals($before, $grades(), 'the grades of the activities are the same');
        $category = \grade_category::fetch_course_category($course->id);
        $this->assertEquals(GRADE_AGGREGATE_MEAN, $category->aggregation);
        $this->assertEquals(11.0, $DB->get_field('grade_grades', 'finalgrade', ['itemid' => $total->id,
            'userid' => $students[3]->id]), 'the typed total stayed');
        // The control: "keep" changes nothing.
        $again = updater::preview($course, $ops('keep'), '', null);
        $this->assertNull($this->item($again, 'cs:gradebook'), 'already as asked: nothing to choose');
    }

    public function test_a_block_with_data_of_a_person_cannot_be_replaced_or_removed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $course = $g->create_course();
        $student = $g->create_and_enrol($course, 'student');
        $context = \context_course::instance($course->id);
        $comments = $g->create_block('comments', ['parentcontextid' => $context->id, 'pagetypepattern' => 'course-view-*',
            'configdata' => base64_encode(serialize(new \stdClass()))]);
        $html = $g->create_block('html', ['parentcontextid' => $context->id, 'pagetypepattern' => 'course-view-*',
            'configdata' => base64_encode(serialize(new \stdClass()))]);
        $DB->insert_record('comments', (object) ['contextid' => \context_block::instance($comments->id)->id,
            'component' => 'block_comments',
            'commentarea' => 'page_comments', 'itemid' => 0, 'content' => 'COMENTARIO-DO-ALUNO', 'format' => 0,
                'userid' => $student->id,
            'timecreated' => time()]);
        $blocks = [
            ['proposal' => 'remove', 'choice' => 'apply', 'name' => 'comments', 'instanceid' => (int) $comments->id],
            ['proposal' => 'replace', 'choice' => 'apply', 'name' => 'html', 'instanceid' => (int) $html->id,
                'config' => ['title' => 'Novo', 'text' => 'novo']],
            ['proposal' => 'add', 'choice' => 'apply', 'name' => 'calendar_month', 'config' => []],
        ];
        $preview = updater::preview($course, $this->ops(['coursesettings' => ['blocks' => $blocks]]), '', null);
        $remove = $this->item($preview, 'cs:block:0');
        $this->assertSame('skip', $remove['action']);
        $this->assertSame('persondata', $remove['reason']);
        $this->assertFalse($remove['canchoose']);
        $this->assertSame('update', $this->item($preview, 'cs:block:1')['action']);
        $this->assertSame('update', $this->item($preview, 'cs:block:2')['action']);
        $this->assertSame(0, $DB->count_records('block_instances', ['blockname' => 'calendar_month',
            'parentcontextid' => $context->id]));
        // Adding a block the page already has is not offered: there is nothing to choose.
        $again = updater::preview($course, $this->ops(['coursesettings' => ['blocks' => [
            ['proposal' => 'add', 'choice' => 'apply', 'name' => 'html', 'config' => []]]]]), '', null);
        $this->assertNull($this->item($again, 'cs:block:0'));
        $record = $this->apply($preview);
        $this->assertSame((int) restorer::STATUS_FINISHED, (int) $record->status, (string) $record->errormessage);
        $this->assertTrue($DB->record_exists('block_instances', ['id' => $comments->id]), 'the block with the comment stayed');
        $this->assertSame(1, $DB->count_records('comments', ['userid' => $student->id]));
        $this->assertSame(1, $DB->count_records('block_instances', ['blockname' => 'calendar_month',
            'parentcontextid' => $context->id]));
        $config = unserialize(base64_decode($DB->get_field('block_instances', 'configdata', ['id' => $html->id])));
        $this->assertSame('Novo', $config->title);
        // The control: with the comment gone, the same removal is possible.
        $DB->delete_records('comments', ['userid' => $student->id]);
        $preview = updater::preview($course, $this->ops(['coursesettings' => ['blocks' => [$blocks[0]]]]), '', null);
        $this->assertSame('update', $this->item($preview, 'cs:block:0')['action']);
        $this->apply($preview);
        $this->assertFalse($DB->record_exists('block_instances', ['id' => $comments->id]));
    }

    public function test_a_package_that_is_not_what_was_announced_is_refused(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $s1 = (int) \course_get_format($a->course)->get_section(1)->id;
        $announced = [
            ['key' => 'p1', 'module' => 'page', 'name' => 'Pagina nova', 'section' => $s1],
            ['key' => 'p2', 'module' => 'page', 'name' => 'Pagina da secao nova', 'section' => 'ns1'],
        ];
        $news = [['key' => 'ns1', 'number' => 4, 'name' => 'Secao nova quatro']];
        // The control: what the package has is what is announced.
        $ok = updater::preview($a->course, $this->ops(['added' => $announced, 'newsections' => $news]), '', $this->package());
        $this->assertSame(3, $ok['counts']['add']);
        $before = $DB->count_records('local_miwlab_updates');
        // Another name; one activity too few; a new section that is not the next number; no package at all.
        $cases = [
            'name' => ['added' => [['key' => 'p1', 'module' => 'page', 'name' => 'Outro nome', 'section' => $s1],
                $announced[1]], 'newsections' => $news],
            'few' => ['added' => [$announced[0]], 'newsections' => $news],
            'number' => ['added' => $announced, 'newsections' => [['key' => 'ns1', 'number' => 6, 'name' => 'Secao nova quatro']]],
            'section' => ['added' => [['key' => 'p1', 'module' => 'page', 'name' => 'Pagina nova',
                'section' => (int) \course_get_format($a->course)->get_section(2)->id],
                $announced[1]], 'newsections' => $news],
        ];
        foreach ($cases as $name => $parts) {
            try {
                updater::preview($a->course, $this->ops($parts), '', $this->package());
                $this->fail('a package that is not the announced one was accepted: ' . $name);
            } catch (\moodle_exception $e) {
                $this->assertSame('error:packagemismatch', $e->errorcode, $name);
            }
        }
        $this->assertSame($before, $DB->count_records('local_miwlab_updates'), 'a refused preview is not kept');
        try {
            updater::preview($a->course, $this->ops(['added' => $announced, 'newsections' => $news]), '', null);
            $this->fail('new activities without the package');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:draftfilenotfound', $e->errorcode);
        }
    }

    public function test_a_text_that_cites_a_file_the_activity_does_not_have_is_left_out(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $a = $this->course_with_people();
        $context = \context_module::instance($a->cm->page->id);
        get_file_storage()->create_file_from_string(['contextid' => $context->id, 'component' => 'mod_page',
            'filearea' => 'content',
            'itemid' => 0, 'filepath' => '/', 'filename' => 'ja-existe.png'], 'png');
        $html = fn(string $file) => '<p><img src="@@PLUGINFILE@@/' . $file . '"></p>';
        $preview = updater::preview($a->course, $this->ops(['activities' => [
            ['cmid' => (int) $a->cm->page->id, 'text' => ['field' => 'content', 'html' => $html('novo.png')]],
        ]]), '', null);
        $item = $this->item($preview, 'a:' . $a->cm->page->id . ':text');
        $this->assertSame('skip', $item['action']);
        $this->assertSame('newfile', $item['reason']);
        // The control: the same text with a file the activity has.
        $preview = updater::preview($a->course, $this->ops(['activities' => [
            ['cmid' => (int) $a->cm->page->id, 'text' => ['field' => 'content', 'html' => $html('ja-existe.png')]],
        ]]), '', null);
        $this->assertSame('update', $this->item($preview, 'a:' . $a->cm->page->id . ':text')['action']);
    }

    public function test_slots_keep_the_places_of_what_is_not_listed(): void {
        $this->assertSame([1, 9, 2, 3], update_runner::slots([1, 2, 9, 3], [9, 2, 3], [9, 2, 3]));
        $this->assertSame([5, 2, 1], update_runner::slots([5, 1, 2], [2, 1], [1, 2]));
        $this->assertSame([7, 1, 2], update_runner::slots([7], [1, 2], [1, 2]), 'the surplus goes to the end');
        $this->assertSame([4, 5], update_runner::slots([4, 5], [], []));
    }
}
