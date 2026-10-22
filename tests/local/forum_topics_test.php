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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\forum_topics::class)]
/**
 * Tests of the opening topics of the forums (1.7.0, issue 178): which topics are read from a course (only the ones the staff
 * started), and how the topics a package carries are recreated (no duplicate, no notification, nothing in a single discussion
 * forum, no file that does not match its SHA-1).
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\forum_topics
 */
final class forum_topics_test extends \advanced_testcase {
    /**
     * Starts a discussion in a forum.
     *
     * @param \stdClass $course
     * @param \stdClass $forum
     * @param \stdClass $user
     * @param string $name
     * @param array $extra
     * @return \stdClass
     */
    protected function discussion(
        \stdClass $course,
        \stdClass $forum,
        \stdClass $user,
        string $name,
        array $extra = []
    ): \stdClass {
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        return $generator->create_discussion($extra + [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $user->id,
            'name' => $name,
            'message' => '<p>Texto de ' . $name . '</p>',
        ]);
    }

    /**
     * The titles of the topics of a forum in the result of the read.
     *
     * @param array $result
     * @param int $cmid
     * @return string[]
     */
    protected function titles(array $result, int $cmid): array {
        foreach ($result['forums'] as $forum) {
            if ($forum['cmid'] === $cmid) {
                return array_column($forum['topics'], 'subject');
            }
        }
        return [];
    }

    public function test_only_the_topics_the_staff_started_are_read(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Dúvidas', 'type' => 'qanda']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $assistant = $generator->create_and_enrol($course, 'teacher');
        $student = $generator->create_and_enrol($course, 'student');
        $manager = $generator->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager'], MUST_EXIST);
        role_assign($managerrole, $manager->id, \context_coursecat::instance($course->category)->id);
        $this->discussion($course, $forum, $teacher, 'Abertura da professora');
        $this->discussion($course, $forum, $assistant, 'Abertura da assistente');
        $this->discussion($course, $forum, $manager, 'Abertura da gerência');
        $this->discussion($course, $forum, get_admin(), 'Abertura do administrador');
        // The control: a student's discussion exists and is never read.
        $this->discussion($course, $forum, $student, 'Pergunta de aluno');
        $this->assertSame(5, $DB->count_records('forum_discussions', ['forum' => $forum->id]));

        $result = forum_topics::read($course);
        $titles = $this->titles($result, (int) $forum->cmid);
        sort($titles);
        $this->assertSame(
            ['Abertura da assistente', 'Abertura da gerência', 'Abertura da professora', 'Abertura do administrador'],
            $titles
        );
        $this->assertNotContains('Pergunta de aluno', $titles);
        $roles = array_column($result['forums'][0]['topics'], 'authorrole', 'subject');
        $this->assertSame('editingteacher', $roles['Abertura da professora']);
        $this->assertSame('teacher', $roles['Abertura da assistente']);
        $this->assertSame('manager', $roles['Abertura da gerência']);
        $this->assertSame('admin', $roles['Abertura do administrador']);
        $this->assertSame('qanda', $result['forums'][0]['type']);
        // Nothing that names a person.
        $this->assertStringNotContainsString($teacher->lastname, json_encode($result));
        $this->assertStringNotContainsString($student->lastname, json_encode($result));
    }

    public function test_a_single_discussion_forum_a_group_a_period_and_a_deleted_post_are_not_read(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $single = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Única', 'type' => 'single']);
        $general = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Geral']);
        $group = $generator->create_group(['courseid' => $course->id]);
        $this->discussion($course, $general, $teacher, 'Normal');
        $this->discussion($course, $general, $teacher, 'De um grupo', ['groupid' => $group->id]);
        $this->discussion($course, $general, $teacher, 'Com período', ['timestart' => time() + 3600]);
        $deleted = $this->discussion($course, $general, $teacher, 'Apagada');
        $DB->set_field('forum_posts', 'deleted', 1, ['discussion' => $deleted->id]);
        // The control: the single discussion forum does have a discussion, and it is not read.
        $this->assertGreaterThan(0, $DB->count_records('forum_discussions', ['forum' => $single->id]));

        $result = forum_topics::read($course);
        $this->assertSame(['Normal'], $this->titles($result, (int) $general->cmid));
        $this->assertSame([], $this->titles($result, (int) $single->cmid));
        $this->assertCount(1, $result['forums']);
    }

    public function test_the_files_of_a_topic_come_with_it_and_the_large_ones_are_left_out(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Avisos']);
        $discussion = $this->discussion($course, $forum, $teacher, 'Com anexo', [
            'message' => '<p>Veja <img src="@@PLUGINFILE@@/f.png"></p>',
        ]);
        $context = \context_module::instance($forum->cmid);
        $fs = get_file_storage();
        $base = ['contextid' => $context->id, 'component' => 'mod_forum', 'itemid' => $discussion->firstpost, 'filepath' => '/'];
        $fs->create_file_from_string($base + ['filearea' => 'attachment', 'filename' => 'roteiro.txt'], 'conteúdo do roteiro');
        $fs->create_file_from_string($base + ['filearea' => 'post', 'filename' => 'f.png'], 'PNG falso');
        $fs->create_file_from_string(
            $base + ['filearea' => 'attachment', 'filename' => 'grande.bin'],
            str_repeat('x', forum_topics::MAX_FILE_BYTES + 1)
        );

        $topic = forum_topics::read($course)['forums'][0]['topics'][0];
        $files = array_column($topic['files'], null, 'filename');
        $names = array_keys($files);
        sort($names);
        $this->assertSame(['f.png', 'roteiro.txt'], $names);
        $this->assertSame('conteúdo do roteiro', base64_decode($files['roteiro.txt']['content']));
        $this->assertFalse($files['roteiro.txt']['embedded']);
        $this->assertTrue($files['f.png']['embedded']);
        $this->assertSame(1, $topic['omittedfiles']);
        $this->assertStringContainsString('@@PLUGINFILE@@/f.png', $topic['message']);
    }

    /**
     * Writes a package: the JSON of the topics and the files.
     *
     * @param array $forums
     * @param array $files Content of each file (the SHA-1 of the content names the file).
     * @return string The folder.
     */
    protected function package(array $forums, array $files = []): string {
        $dir = make_temp_directory('miwlab_forum_topics_' . random_string(8));
        make_writable_directory($dir . '/miwlab/foruns');
        file_put_contents($dir . '/' . forum_topics::PACKAGE_JSON, json_encode(['formato' => 1, 'foruns' => $forums]));
        foreach ($files as $content) {
            file_put_contents($dir . '/miwlab/foruns/' . sha1($content), $content);
        }
        return $dir;
    }

    /**
     * A course as a restore leaves it: a forum of each kind, in section 1.
     *
     * @return array [course, forums by name]
     */
    protected function restored_course(): array {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['numsections' => 2]);
        $forums = [];
        foreach (['Geral' => 'general', 'Dúvidas' => 'qanda', 'Única' => 'single', 'Avisos' => 'news'] as $name => $type) {
            $forums[$name] = $generator->create_module(
                'forum',
                ['course' => $course->id, 'name' => $name, 'type' => $type, 'section' => 1]
            );
        }
        return [$course, $forums];
    }

    /**
     * The entry of a forum in foruns.json.
     *
     * @param string $name
     * @param int $position Among the forums of section 1.
     * @param array $topics
     * @return array
     */
    protected function entry(string $name, int $position, array $topics): array {
        return ['secao' => 1, 'posicao' => $position, 'nome' => $name, 'topicos' => $topics];
    }

    public function test_the_topics_of_a_package_are_created_once_and_without_notification(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $anexo = 'conteúdo do anexo';
        $dir = $this->package([
            $this->entry('Geral', 0, [
                ['titulo' => 'Boas-vindas', 'texto' => '<p>Olá a todas e todos.</p>', 'fixado' => true,
                    'anexos' => [['nome' => 'guia.txt', 'sha1' => sha1($anexo)]]],
                ['titulo' => 'Como estudar', 'texto' => '<p>Passo a passo.</p>'],
            ]),
            $this->entry('Dúvidas', 1, [['titulo' => 'Pergunte aqui', 'texto' => '<p>Dúvidas?</p>']]),
        ], [$anexo]);

        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(['created' => 3, 'skipped' => 0, 'existing' => 0, 'note' => ''], $result);
        $this->assertSame(
            ['Boas-vindas', 'Como estudar'],
            array_values($DB->get_fieldset_select('forum_discussions', 'name', 'forum = ? ORDER BY id', [$forums['Geral']->id]))
        );
        // Started by the account that ran the restore; the first post is already mailed, so the cron sends nothing.
        $discussion = $DB->get_record(
            'forum_discussions',
            ['forum' => $forums['Geral']->id, 'name' => 'Boas-vindas'],
            '*',
            MUST_EXIST
        );
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
        $this->assertEquals(get_admin()->id, $post->userid);
        $this->assertEquals(FORUM_MAILED_SUCCESS, $post->mailed);
        $this->assertSame('<p>Olá a todas e todos.</p>', $post->message);
        $this->assertEquals(FORUM_DISCUSSION_PINNED, $discussion->pinned);
        $this->assertEquals(1, $post->attachment);
        $files = get_file_storage()->get_area_files(
            \context_module::instance($forums['Geral']->cmid)->id,
            'mod_forum',
            'attachment',
            $post->id,
            'filename',
            false
        );
        $this->assertSame('conteúdo do anexo', reset($files)->get_content());

        // The control of the duplicate: the same package applied again creates nothing more.
        $again = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(['created' => 0, 'skipped' => 0, 'existing' => 3, 'note' => ''], $again);
        $this->assertSame(2, $DB->count_records('forum_discussions', ['forum' => $forums['Geral']->id]));
        $this->assertSame(1, $DB->count_records('forum_discussions', ['forum' => $forums['Dúvidas']->id]));
    }

    public function test_the_single_discussion_forum_is_left_to_moodle_and_the_news_forum_takes_a_topic(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $dir = $this->package([
            $this->entry('Única', 2, [['titulo' => 'Não deve existir', 'texto' => '<p>x</p>']]),
            $this->entry('Avisos', 3, [['titulo' => 'Calendário', 'texto' => '<p>Datas.</p>']]),
        ]);
        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(
            0,
            $DB->count_records('forum_discussions', ['forum' => $forums['Única']->id, 'name' => 'Não deve existir'])
        );
        $this->assertSame(1, $DB->count_records('forum_discussions', ['forum' => $forums['Avisos']->id, 'name' => 'Calendário']));
    }

    public function test_a_forum_that_does_not_match_is_skipped_with_the_reason(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        // Right position, wrong name; and a position that does not exist.
        $dir = $this->package([
            $this->entry('Outro nome', 0, [['titulo' => 'A', 'texto' => '<p>a</p>']]),
            $this->entry('Geral', 9, [['titulo' => 'B', 'texto' => '<p>b</p>']]),
        ]);
        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertStringContainsString('Outro nome', $result['note']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forums['Geral']->id]));
    }

    public function test_without_the_capability_nothing_is_created_and_the_reason_names_it(): void {
        global $DB;
        $this->resetAfterTest();
        [$course, $forums] = $this->restored_course();
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $dir = $this->package([$this->entry('Geral', 0, [['titulo' => 'Sem permissão', 'texto' => '<p>x</p>']])]);
        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('mod/forum:startdiscussion', $result['note']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forums['Geral']->id]));
    }

    public function test_a_package_that_is_not_what_the_miwlab_writes_creates_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $dir = make_temp_directory('miwlab_forum_topics_' . random_string(8));
        make_writable_directory($dir . '/miwlab');
        $invalid = [
            'not json',
            '{"formato":2,"foruns":[]}',
            '{"formato":1,"foruns":[{"secao":"1"}]}',
            '{"formato":1,"foruns":[{"secao":1,"posicao":0,"nome":"Geral","topicos":[{"titulo":" ","texto":"x"}]}]}',
        ];
        foreach ($invalid as $content) {
            file_put_contents($dir . '/' . forum_topics::PACKAGE_JSON, $content);
            $result = forum_topics::create_from_package($course->id, $dir);
            $this->assertSame(0, $result['created'], $content);
            $this->assertSame(1, $result['skipped'], $content);
        }
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forums['Geral']->id]));
        // The control: a package without the file is just a package without topics.
        unlink($dir . '/' . forum_topics::PACKAGE_JSON);
        $this->assertSame(
            ['created' => 0, 'skipped' => 0, 'existing' => 0, 'note' => ''],
            forum_topics::create_from_package($course->id, $dir)
        );
    }

    public function test_a_file_that_does_not_match_its_sha1_is_not_attached(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $content = 'o que o pacote diz';
        $dir = $this->package(
            [$this->entry('Geral', 0, [['titulo' => 'Com anexo trocado', 'texto' => '<p>x</p>',
                'anexos' => [['nome' => 'a.txt', 'sha1' => sha1($content)]]]])],
            [$content]
        );
        // The content of the file changed after the package was written.
        file_put_contents($dir . '/miwlab/foruns/' . sha1($content), 'outro conteúdo');
        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(1, $result['created']);
        $post = $DB->get_record_sql(
            'SELECT p.* FROM {forum_posts} p JOIN {forum_discussions} d ON d.firstpost = p.id WHERE d.forum = ?',
            [$forums['Geral']->id]
        );
        $this->assertEmpty($post->attachment);
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_module::instance($forums['Geral']->cmid)->id,
            'mod_forum',
            'attachment',
            $post->id,
            'id',
            false
        ));
    }

    public function test_a_package_with_more_topics_than_the_ceiling_creates_none(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $entries = [];
        for ($i = 0; $i < 11; $i++) {
            $topics = [];
            for ($j = 0; $j < 50; $j++) {
                $topics[] = ['titulo' => "T$i-$j", 'texto' => '<p>x</p>'];
            }
            $entries[] = $this->entry('Geral', 0, $topics);
        }
        $result = forum_topics::create_from_package($course->id, $this->package($entries));
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forums['Geral']->id]));
    }

    public function test_a_file_in_the_text_is_held_to_the_size_ceiling_too_and_a_failure_leaves_no_half_made_topic(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $forums] = $this->restored_course();
        $big = str_repeat('x', forum_topics::MAX_FILE_BYTES + 1);
        $small = 'pequeno';
        $dir = $this->package(
            [$this->entry('Geral', 0, [['titulo' => 'Com imagem grande', 'texto' => '<p>x</p>', 'anexos' => [
                ['nome' => 'grande.png', 'sha1' => sha1($big), 'embutido' => true],
                ['nome' => 'pequena.png', 'sha1' => sha1($small), 'embutido' => true],
            ]]])],
            [$big, $small]
        );
        $this->assertSame(1, forum_topics::create_from_package($course->id, $dir)['created']);
        $post = $DB->get_record_sql(
            'SELECT p.* FROM {forum_posts} p JOIN {forum_discussions} d ON d.firstpost = p.id WHERE d.forum = ?',
            [$forums['Geral']->id]
        );
        $names = array_map(function ($f) {
            return $f->get_filename();
        }, get_file_storage()->get_area_files(
            \context_module::instance($forums['Geral']->cmid)->id,
            'mod_forum',
            'post',
            $post->id,
            'filename',
            false
        ));
        $this->assertSame(['pequena.png'], array_values($names));

        // The same file name twice makes the second file fail: the whole topic is undone, not left half made.
        $dir = $this->package(
            [$this->entry('Dúvidas', 1, [['titulo' => 'Meio feito', 'texto' => '<p>x</p>', 'anexos' => [
                ['nome' => 'a.png', 'sha1' => sha1($small), 'embutido' => true],
                ['nome' => 'a.png', 'sha1' => sha1($small), 'embutido' => true],
            ]]])],
            [$small]
        );
        $result = forum_topics::create_from_package($course->id, $dir);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $DB->count_records('forum_discussions', ['forum' => $forums['Dúvidas']->id]));
        $this->assertSame(0, $DB->count_records('forum_posts', ['subject' => 'Meio feito']));
    }

    public function test_the_read_does_not_load_the_posts_of_people_who_are_not_staff(): void {
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Geral']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $this->discussion($course, $forum, $teacher, 'Da professora');
        // Many discussions by students: the read goes through the database filter and keeps only the staff topic.
        for ($i = 0; $i < 30; $i++) {
            $this->discussion($course, $forum, $generator->create_and_enrol($course, 'student'), "Do aluno $i");
        }
        $result = forum_topics::read($course);
        $this->assertSame(['Da professora'], $this->titles($result, (int) $forum->cmid));
        $this->assertSame(0, $result['omittedtopics']);
    }
}
