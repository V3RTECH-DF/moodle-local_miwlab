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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_forum_topics::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\forum_topics::class)]
/**
 * Tests of the opening topics of the forums through the web service and through the restore and the replacement of a package
 * (1.7.0, issue 178).
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\get_forum_topics
 * @covers     \local_miwlab\local\forum_topics
 */
final class forum_topics_test extends \advanced_testcase {
    /** @var string[] What the account needs, beyond restoring and replacing, to recreate the topics. */
    const FORUM_CAPABILITIES = [
        'mod/forum:startdiscussion', 'mod/forum:addnews', 'mod/forum:createattachment', 'mod/forum:pindiscussions',
    ];

    /**
     * Tidies the hooks after each test.
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
        $roleid = create_role('Topics ' . $user->id, 'topics' . $user->id, '');
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
     * A package (a backup of a small course with a forum "Conversa") that carries the topics, in the draft area of the user.
     *
     * @param \stdClass $user
     * @param array $forums The entries of miwlab/foruns.json.
     * @param string[] $files Content of the files of the topics.
     * @return int The draft item id.
     */
    protected function package_in_draft(\stdClass $user, array $forums, array $files = []): int {
        global $CFG;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $generator = $this->getDataGenerator();
        $origin = $generator->create_course(['fullname' => 'Origem', 'enablecompletion' => 1]);
        $generator->create_module('page', ['course' => $origin->id, 'name' => 'Leitura']);
        $generator->create_module('forum', ['course' => $origin->id, 'name' => 'Conversa']);
        $generator->create_module('forum', ['course' => $origin->id, 'name' => 'Boas-vindas', 'type' => 'single']);
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
        // The package as the MiwLab writes it: the backup plus the topics, outside what Moodle reads.
        $packer = get_file_packer('application/vnd.moodle.backup');
        $dir = make_request_directory();
        $backup->extract_to_pathname($packer, $dir);
        make_writable_directory($dir . '/miwlab/foruns');
        file_put_contents($dir . '/miwlab/foruns.json', json_encode(['formato' => 1, 'foruns' => $forums]));
        foreach ($files as $content) {
            file_put_contents($dir . '/miwlab/foruns/' . sha1($content), $content);
        }
        $list = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $path) {
            if ($path->isFile()) {
                $list[substr($path->getPathname(), strlen($dir) + 1)] = $path->getPathname();
            }
        }
        $target = make_request_directory() . '/pacote.mbz';
        $this->assertTrue($packer->archive_to_pathname($list, $target));
        $draftitemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($user->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftitemid,
            'filepath' => '/',
            'filename' => 'pacote.mbz',
        ], $target);
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
     * The titles of the discussions of the forum of the course with the name.
     *
     * @param int $courseid
     * @param string $forumname
     * @return string[]
     */
    protected function titles(int $courseid, string $forumname): array {
        global $DB;
        $sql = 'SELECT d.id, d.name FROM {forum_discussions} d JOIN {forum} f ON f.id = d.forum
                 WHERE f.course = :course AND f.name = :name ORDER BY d.id';
        $titles = array_values(array_map(function ($row) {
            return $row->name;
        }, $DB->get_records_sql($sql, ['course' => $courseid, 'name' => $forumname])));
        return $titles;
    }

    public function test_a_new_course_gets_the_topics_of_the_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, array_merge(restorer::CAPABILITIES, self::FORUM_CAPABILITIES));
        $anexo = 'roteiro do módulo';
        $draftitemid = $this->package_in_draft($user, [
            ['secao' => 0, 'posicao' => 0, 'nome' => 'Conversa', 'topicos' => [
                ['titulo' => 'Comece por aqui', 'texto' => '<p>Bem-vinda.</p>',
                    'anexos' => [['nome' => 'roteiro.txt', 'sha1' => sha1($anexo)]]],
                ['titulo' => 'Combinados', 'texto' => '<p>Respeito.</p>'],
            ]],
            ['secao' => 0, 'posicao' => 1, 'nome' => 'Boas-vindas', 'topicos' => [['titulo' => 'Não', 'texto' => '<p>x</p>']]],
        ], [$anexo]);

        $this->setUser($user);
        $queued = base::clean_returnvalue(
            restore_course::execute_returns(),
            restore_course::execute($category->id, $draftitemid, '', 'miwlab:curso-t:v1')
        );
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->setUser($user);
        $status = base::clean_returnvalue(get_restore_status::execute_returns(), get_restore_status::execute($queued['restoreid']));
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame(2, $status['topicscreated']);
        $this->assertSame(0, $status['topicsskipped']);
        $this->assertSame('', $status['topicsnote']);
        $this->assertSame(['Comece por aqui', 'Combinados'], $this->titles($status['courseid'], 'Conversa'));
        // The single discussion forum keeps what Moodle made (its own discussion), not the topic of the package.
        $this->assertNotContains('Não', $this->titles($status['courseid'], 'Boas-vindas'));
        $this->assertCount(1, $this->titles($status['courseid'], 'Boas-vindas'));
    }

    public function test_without_the_forum_capabilities_the_course_is_restored_and_the_topics_are_counted_as_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, restorer::CAPABILITIES);
        $draftitemid = $this->package_in_draft($user, [
            ['secao' => 0, 'posicao' => 0, 'nome' => 'Conversa', 'topicos' => [['titulo' => 'Comece', 'texto' => '<p>Oi.</p>']]],
        ]);
        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->setUser($user);
        $status = base::clean_returnvalue(get_restore_status::execute_returns(), get_restore_status::execute($queued['restoreid']));
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame(0, $status['topicscreated']);
        $this->assertSame(1, $status['topicsskipped']);
        $this->assertStringContainsString('mod/forum:startdiscussion', $status['topicsnote']);
        $this->assertSame([], $this->titles($status['courseid'], 'Conversa'));
    }

    public function test_a_package_without_topics_restores_as_before(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $category = $this->getDataGenerator()->create_category();
        $user = $this->user_in_category($category, array_merge(restorer::CAPABILITIES, self::FORUM_CAPABILITIES));
        $draftitemid = $this->package_in_draft($user, []);
        $this->setUser($user);
        $queued = restore_course::execute($category->id, $draftitemid);
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $this->setUser($user);
        $status = base::clean_returnvalue(get_restore_status::execute_returns(), get_restore_status::execute($queued['restoreid']));
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertSame([0, 0, ''], [$status['topicscreated'], $status['topicsskipped'], $status['topicsnote']]);
        $this->assertSame([], $this->titles($status['courseid'], 'Conversa'));
    }

    public function test_the_replacement_loses_the_old_topics_and_gets_the_new_ones_once(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['fullname' => 'Curso antigo', 'category' => $category->id, 'enablecompletion' => 1]);
        $old = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Conversa']);
        $user = $this->user_in_category($category, array_merge(
            replacer::CAPABILITIES,
            ['moodle/course:view'],
            self::FORUM_CAPABILITIES
        ));
        // The old topic is the account's own (what a previous send made): not progress of a person, and goes with the content.
        $generator->get_plugin_generator('mod_forum')->create_discussion([
            'course' => $course->id, 'forum' => $old->id, 'userid' => $user->id,
            'name' => 'Tópico antigo', 'message' => '<p>Velho</p>',
        ]);
        $json = [['secao' => 0, 'posicao' => 0, 'nome' => 'Conversa',
            'topicos' => [['titulo' => 'Tópico novo', 'texto' => '<p>Novo</p>']]]];

        foreach ([1, 2] as $round) {
            $draftitemid = $this->package_in_draft($user, $json);
            $this->setUser($user);
            $queued = replace_course::execute($course->id, $draftitemid);
            $this->run_queued($user);
            $status = base::clean_returnvalue(
                get_restore_status::execute_returns(),
                get_restore_status::execute($queued['restoreid'])
            );
            $this->assertSame('finished', $status['status'], $status['errormessage']);
            $this->assertSame(1, $status['topicscreated'], 'round ' . $round);
            // The control of the duplicate: the second replacement deletes what the first made, so there is one topic, not two.
            $this->assertSame(['Tópico novo'], $this->titles($course->id, 'Conversa'), 'round ' . $round);
        }
    }

    public function test_a_topic_of_a_teacher_is_progress_and_refuses_the_replacement_but_the_account_own_is_not(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'enablecompletion' => 1]);
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Conversa']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $user = $this->user_in_category($category, array_merge(
            replacer::CAPABILITIES,
            ['moodle/course:view'],
            self::FORUM_CAPABILITIES
        ));
        $plugin = $generator->get_plugin_generator('mod_forum');
        $plugin->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $user->id,
            'name' => 'Da conta', 'message' => '<p>x</p>',
        ]);
        $this->setUser($user);
        $this->assertSame(0, replacer::people($course->id)['withprogress']);
        $plugin->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher->id,
            'name' => 'Da professora', 'message' => '<p>x</p>',
        ]);
        $this->assertSame(1, replacer::people($course->id)['withprogress']);
    }

    public function test_reading_needs_the_capability_in_the_course_and_returns_the_staff_topics_only(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Conversa']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $plugin = $generator->get_plugin_generator('mod_forum');
        $plugin->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher->id,
            'name' => 'Da professora', 'message' => '<p>Oi</p>',
        ]);
        $plugin->create_discussion([
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $student->id,
            'name' => 'Do aluno', 'message' => '<p>Oi</p>',
        ]);
        $category = \core_course_category::get($course->category, MUST_EXIST, true);

        $without = $this->user_in_category($category, ['webservice/rest:use']);
        $this->setUser($without);
        try {
            get_forum_topics::execute($course->id);
            $this->fail('read without the capability');
        } catch (\required_capability_exception $e) {
            $this->assertSame(get_capability_string('local/miwlab:readforumtopics'), $e->a);
        }

        $with = $this->user_in_category($category, ['local/miwlab:readforumtopics']);
        $this->setUser($with);
        $result = base::clean_returnvalue(get_forum_topics::execute_returns(), get_forum_topics::execute($course->id));
        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertSame(['Da professora'], array_column($result['forums'][0]['topics'], 'subject'));

        $this->expectException(\moodle_exception::class);
        get_forum_topics::execute(SITEID);
    }

    public function test_the_running_account_is_left_out_only_of_the_forum_posts(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $category = $generator->create_category();
        $course = $generator->create_course(['category' => $category->id, 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Leitura']);
        $user = $this->user_in_category($category, array_merge(replacer::CAPABILITIES, ['moodle/course:view']));
        $this->setUser($user);
        $this->assertSame(0, replacer::people($course->id)['withprogress']);
        // Any other trace of the running account (here, an activity completed) still counts: only its forum posts do not.
        $DB->insert_record('course_modules_completion', (object) [
            'coursemoduleid' => $page->cmid, 'userid' => $user->id, 'completionstate' => 1, 'viewed' => 1, 'timemodified' => time(),
        ]);
        $this->assertSame(1, replacer::people($course->id)['withprogress']);
    }
}
