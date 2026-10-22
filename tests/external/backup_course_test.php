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

use local_miwlab\local\exporter;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\backup_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_backup_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\delete_backup::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\get_backup_chunk::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\exporter::class)]
/**
 * Tests of the backup of the content of a course for the MiwLab (1.6.0, issue 155): the backup carries the content and no data of
 * a person, it is served only to whoever can back up that course, the capabilities are named, the site course is refused, and
 * it is deleted on request and after a day.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\external\backup_course
 * @covers     \local_miwlab\external\get_backup_status
 * @covers     \local_miwlab\external\delete_backup
 * @covers     \local_miwlab\external\get_backup_chunk
 * @covers     \local_miwlab\local\exporter
 */
final class backup_course_test extends \advanced_testcase {
    /**
     * A user with a role given in the category of the course, holding the capabilities asked.
     *
     * @param int $categoryid
     * @param string[] $capabilities
     * @return \stdClass
     */
    protected function user_with(int $categoryid, array $capabilities): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Backup ' . $user->id, 'backup' . $user->id, '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $context = \context_coursecat::instance($categoryid);
        foreach (array_merge($capabilities, ['moodle/course:view', 'moodle/course:viewhiddencourses']) as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, $context->id, true);
        }
        role_assign($roleid, $user->id, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        return $user;
    }

    /**
     * A course with a page (with an image in the content), a forum with a post of a student, and the student enrolled.
     *
     * @return \stdClass
     */
    protected function course(): \stdClass {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['fullname' => 'Curso com conteudo']);
        $page = $generator->create_module('page', ['course' => $course->id, 'name' => 'Leitura',
            'content' => '<p>Texto <img src="@@PLUGINFILE@@/f.png"></p>']);
        get_file_storage()->create_file_from_string(
            ['contextid' => \context_module::instance($page->cmid)->id,
            'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0, 'filepath' => '/', 'filename' => 'f.png'],
            'imagem-da-pagina'
        );
        $forum = $generator->create_module('forum', ['course' => $course->id, 'name' => 'Conversa']);
        $student = $generator->create_and_enrol(
            $course,
            'student',
            ['username' => 'sentinela', 'email' => 'sentinela@example.invalid']
        );
        $generator->get_plugin_generator('mod_forum')->create_discussion(['course' => $course->id, 'forum' => $forum->id,
            'userid' => $student->id, 'message' => 'MENSAGEM-SENTINELA']);
        return $course;
    }

    /**
     * Runs the queued background tasks of the plugin.
     */
    protected function run_tasks(): void {
        ob_start();
        $this->runAdhocTasks('\local_miwlab\task\backup_course');
        ob_end_clean();
    }

    public function test_the_backup_has_the_content_and_no_person_and_is_served_to_whoever_asked(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course();
        $user = $this->user_with((int) $course->category, ['local/miwlab:backupcourse', 'moodle/backup:backupcourse']);
        $this->setUser($user);
        $queued = backup_course::execute((int) $course->id);
        $this->assertSame('queued', $queued['status']);
        $this->run_tasks();
        $this->setUser($user);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $queued['secret']);
        $status = get_backup_status::execute($queued['backupid'], $queued['secret']);
        $this->assertSame('finished', $status['status'], $status['errormessage']);
        $this->assertGreaterThan(0, $status['filesize']);
        $this->assertArrayNotHasKey('secret', $status);
        $this->assertArrayNotHasKey('filepath', $status);
        $context = \context_course::instance($course->id);
        $file = get_file_storage()->get_file(
            $context->id,
            'local_miwlab',
            'contentbackup',
            $queued['backupid'],
            '/',
            $status['filename']
        );
        $this->assertNotFalse($file);
        // The content is there and no person: open the backup.
        $dir = make_request_directory();
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($file, $dir);
        $page = glob($dir . '/activities/page_*/page.xml');
        $this->assertCount(1, $page);
        $this->assertStringContainsString('@@PLUGINFILE@@/f.png', file_get_contents($page[0]));
        $this->assertStringContainsString('<filename>f.png</filename>', file_get_contents($dir . '/files.xml'));
        $this->assertFileDoesNotExist($dir . '/users.xml');
        $everything = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (substr($f->getFilename(), -4) === '.xml') {
                $everything .= file_get_contents($f->getPathname());
            }
        }
        $this->assertStringNotContainsString('MENSAGEM-SENTINELA', $everything);
        $this->assertStringNotContainsString('sentinela@example.invalid', $everything);
        // The copy the backup controller made is not left anywhere: not in the private backup area of the account (where a
        // general backup goes), nor in a backup area of the course.
        $this->assertSame(0, $DB->count_records_select(
            'files',
            "((component = 'user' AND filearea = 'backup') OR component = 'backup') AND filename <> '.'"
        ));
        // Delete: the file and the record go.
        $this->assertSame(['deleted' => true], delete_backup::execute($queued['backupid'], $queued['secret']));
        $this->assertFalse($DB->record_exists('local_miwlab_backups', ['id' => $queued['backupid']]));
        $this->assertFalse(get_file_storage()->get_file(
            $context->id,
            'local_miwlab',
            'contentbackup',
            $queued['backupid'],
            '/',
            $status['filename']
        ));
    }

    public function test_the_missing_capabilities_are_named_and_the_site_course_is_refused(): void {
        $this->resetAfterTest();
        $course = $this->course();
        $this->setUser($this->user_with((int) $course->category, ['moodle/backup:backupcourse']));
        try {
            backup_course::execute((int) $course->id);
            $this->fail('Without local/miwlab:backupcourse the backup must be refused.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcontentcapabilities', $e->errorcode);
            $this->assertStringContainsString('local/miwlab:backupcourse', $e->getMessage());
        }
        $this->setAdminUser();
        $this->expectException(\moodle_exception::class);
        backup_course::execute((int) SITEID);
    }

    public function test_the_status_of_a_backup_of_another_course_is_refused_without_the_capability_there(): void {
        $this->resetAfterTest();
        $course = $this->course();
        $this->setUser($this->user_with((int) $course->category, ['local/miwlab:backupcourse', 'moodle/backup:backupcourse']));
        $queued = backup_course::execute((int) $course->id);
        $other = $this->getDataGenerator()->create_category();
        $this->setUser($this->user_with((int) $other->id, ['local/miwlab:backupcourse', 'moodle/backup:backupcourse']));
        $this->expectException(\moodle_exception::class);
        get_backup_status::execute($queued['backupid'], $queued['secret']);
    }

    public function test_the_file_comes_in_parts_only_to_the_request_that_queued_it(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course();
        $user = $this->user_with((int) $course->category, ['local/miwlab:backupcourse', 'moodle/backup:backupcourse']);
        $this->setUser($user);
        $queued = backup_course::execute((int) $course->id);
        $another = backup_course::execute((int) $course->id);
        $this->run_tasks();
        $this->setUser($user);
        $status = get_backup_status::execute($queued['backupid'], $queued['secret']);
        // The parts, small, put together: the file whose size and SHA-1 the status gave.
        $data = '';
        $offset = 0;
        do {
            $part = get_backup_chunk::execute($queued['backupid'], $queued['secret'], $offset, 100000);
            $this->assertSame($offset, $part['offset']);
            $data .= base64_decode($part['data'], true);
            $offset += $part['length'];
        } while (!$part['eof']);
        $this->assertSame($status['filesize'], strlen($data));
        $this->assertSame($status['contenthash'], sha1($data));
        // The secret of another backup, or none, is refused (as not found).
        foreach (['', $another['secret'], str_repeat('0', 40)] as $wrong) {
            try {
                get_backup_chunk::execute($queued['backupid'], $wrong, 0, 10);
                $this->fail('A part of the backup must not come with another secret.');
            } catch (\moodle_exception $e) {
                $this->assertSame('error:backupnotfound', $e->errorcode);
            }
        }
        // A student of the course, even with the secret, cannot: the capabilities are checked in the course.
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        try {
            get_backup_chunk::execute($queued['backupid'], $queued['secret'], 0, 10);
            $this->fail('Whoever cannot back up the course must not download the backup.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:missingcontentcapabilities', $e->errorcode);
        }
        // The plugin serves nothing through pluginfile.php, and its service does not download files.
        $this->assertFalse(function_exists('local_miwlab_pluginfile'));
        $this->assertSame(0, (int) $DB->get_field('external_services', 'downloadfiles', ['shortname' => 'local_miwlab']));
    }

    public function test_the_cleanup_deletes_the_backups_older_than_a_day(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->course();
        $this->setUser($this->user_with((int) $course->category, ['local/miwlab:backupcourse', 'moodle/backup:backupcourse']));
        $old = backup_course::execute((int) $course->id);
        $new = backup_course::execute((int) $course->id);
        $DB->set_field('local_miwlab_backups', 'timecreated', time() - 2 * DAYSECS, ['id' => $old['backupid']]);
        exporter::cleanup();
        $this->assertFalse($DB->record_exists('local_miwlab_backups', ['id' => $old['backupid']]));
        $this->assertTrue($DB->record_exists('local_miwlab_backups', ['id' => $new['backupid']]));
    }

    public function test_the_service_function_that_queues_the_backup_is_declared_as_write(): void {
        global $CFG;
        $functions = [];
        require($CFG->dirroot . '/local/miwlab/db/services.php');
        $this->assertSame('write', $functions['local_miwlab_backup_course']['type']);
        $this->assertSame('read', $functions['local_miwlab_get_backup_status']['type']);
    }
}
