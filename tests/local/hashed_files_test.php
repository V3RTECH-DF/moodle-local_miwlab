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

use local_miwlab\external\preview_update;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\update_page_files::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\update_operations::class)]
/**
 * Tests of the images named by their digest in the safe update (1.15.0, issue 373): in the content of a Page, the description of
 * an activity and the summary of a section, checked by digest and by real type, never over another file, undone on a failure.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\update_page_files
 * @covers     \local_miwlab\local\update_operations
 */
final class hashed_files_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;
    /** @var \stdClass A Page. */
    protected $page;
    /** @var \stdClass An assignment. */
    protected $assign;
    /** @var int The id of the section 1. */
    protected $section;

    /**
     * A course with a Page, an assignment and a section, as an administrator.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $g = $this->getDataGenerator();
        $this->course = $g->create_course(['numsections' => 2]);
        $this->page = $g->create_module('page', ['course' => $this->course->id, 'content' => '<p>old</p>', 'section' => 1]);
        $this->assign = $g->create_module('assign', ['course' => $this->course->id, 'intro' => '<p>old</p>', 'section' => 1]);
        $this->section = (int) $DB->get_field('course_sections', 'id', ['course' => $this->course->id, 'section' => 1]);
    }

    /**
     * Tidies the hook after each test.
     */
    protected function tearDown(): void {
        update_runner::$stephook = null;
        parent::tearDown();
    }

    /**
     * An image, different for each seed.
     *
     * @param string $type png, jpg, gif or webp
     * @param int $seed
     * @return string
     */
    protected function image(string $type = 'png', int $seed = 1): string {
        if ($type === 'webp') {
            // A lossless WebP of one pixel: GD does not write WebP in every installation.
            return base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
        }
        $image = imagecreatetruecolor(4, 4);
        imagefill($image, 0, 0, imagecolorallocate($image, ($seed * 37) % 256, 0, 0));
        ob_start();
        $write = ['png' => 'imagepng', 'jpg' => 'imagejpeg', 'gif' => 'imagegif'][$type];
        $write($image);
        return ob_get_clean();
    }

    /**
     * An image in the draft area and what the list says of it.
     *
     * @param string $bytes
     * @param string $extension
     * @param string|null $name Another name than the one of the digest.
     * @return array
     */
    protected function draft(string $bytes, string $extension = 'png', ?string $name = null): array {
        global $USER;
        $name = $name ?? 'miwlab-' . hash('sha256', $bytes) . '.' . $extension;
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string(['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $itemid, 'filepath' => '/',
            'filename' => clean_param($name, PARAM_FILE), 'mimetype' => 'text/html'], $bytes);
        return ['draftitemid' => $itemid, 'filename' => $name, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    /**
     * HTML that cites files.
     *
     * @param array $files
     * @return string
     */
    protected function citing(array $files): string {
        return '<p>New</p>' . implode('', array_map(
            fn(array $file): string => '<img src="@@PLUGINFILE@@/' . $file['filename'] . '" alt="">',
            $files
        ));
    }

    /**
     * The operations that put an HTML with files in a field.
     *
     * @param string $field content, intro or summary
     * @param array $files
     * @param string|null $html
     * @return array
     */
    protected function ops(string $field, array $files, ?string $html = null): array {
        $html = $html ?? $this->citing($files);
        if ($field === 'summary') {
            return ['sections' => [['id' => $this->section, 'summary' => $html, 'files' => $files]]];
        }
        $cmid = (int) ($field === 'content' ? $this->page->cmid : $this->assign->cmid);
        return ['activities' => [['cmid' => $cmid, 'text' => ['field' => $field, 'html' => $html, 'files' => $files]]]];
    }

    /**
     * Where the files of a field are kept.
     *
     * @param string $field
     * @return array context id, component, area and item id
     */
    protected function area(string $field): array {
        if ($field === 'summary') {
            return [\context_course::instance($this->course->id)->id, 'course', 'section', $this->section];
        }
        if ($field === 'content') {
            return [\context_module::instance($this->page->cmid)->id, 'mod_page', 'content', 0];
        }
        return [\context_module::instance($this->assign->cmid)->id, 'mod_assign', 'intro', 0];
    }

    /**
     * The HTML a field has.
     *
     * @param string $field
     * @return string
     */
    protected function kept(string $field): string {
        global $DB;
        if ($field === 'summary') {
            return (string) $DB->get_field('course_sections', 'summary', ['id' => $this->section]);
        }
        return $field === 'content' ? (string) $DB->get_field('page', 'content', ['id' => $this->page->id])
            : (string) $DB->get_field('assign', 'intro', ['id' => $this->assign->id]);
    }

    /**
     * The preview of these operations.
     *
     * @param array $ops
     * @return array
     */
    protected function preview(array $ops): array {
        return updater::preview($this->course, json_encode($ops), '', null);
    }

    /**
     * Queues and runs a preview.
     *
     * @param array $preview
     * @return \stdClass
     */
    protected function apply(array $preview): \stdClass {
        global $DB;
        $record = updater::queue(updater::open_preview($preview['previewid']), false, 180);
        $DB->set_field('local_miwlab_restores', 'status', restorer::STATUS_RUNNING, ['id' => $record->id]);
        update_runner::run($DB->get_record('local_miwlab_restores', ['id' => $record->id]));
        return $DB->get_record('local_miwlab_restores', ['id' => $record->id]);
    }

    /**
     * Refused whole, with the code.
     *
     * @param array $ops
     * @param string $case
     */
    protected function assert_refused(array $ops, string $case): void {
        try {
            $this->preview($ops);
            $this->fail('accepted: ' . $case);
        } catch (\moodle_exception $e) {
            $this->assertSame('error:invalidoperations', $e->errorcode, $case);
        }
    }

    /**
     * A JPEG with EXIF (the orientation, in the segment APP1 after the start of the image).
     *
     * @param int $seed
     * @return string
     */
    protected function jpeg_with_exif(int $seed = 1): string {
        $tiff = "MM\x00\x2a\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00" . $tiff;
        $jpeg = $this->image('jpg', $seed);
        return substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($app1) + 2) . $app1 . substr($jpeg, 2);
    }

    /**
     * An image as Moodle receives it by webservice/upload.php (created from a path, the type taken from the name, the removal
     * of EXIF of the site applied): bare, or in the neutral container.
     *
     * @param string $bytes
     * @param bool $container
     * @param string|null $header Another header for the container.
     * @param string|null $content Other bytes than the ones declared.
     * @return array
     */
    protected function uploaded(string $bytes, bool $container, ?string $header = null, ?string $content = null): array {
        global $USER;
        $name = 'miwlab-' . hash('sha256', $bytes) . '.jpg';
        $stored = $container ? $name . update_page_files::CONTAINER : $name;
        $path = make_request_directory() . '/' . $stored;
        $content = $content ?? $bytes;
        file_put_contents($path, $container ? ($header ?? update_page_files::HEADER) . $content : $content);
        $itemid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname(['contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user', 'filearea' => 'draft', 'itemid' => $itemid, 'filepath' => '/', 'filename' => $stored], $path);
        return ['draftitemid' => $itemid, 'filename' => $name, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }

    /**
     * Whether this Moodle rewrites a JPEG it receives (4.5 on: the removal of EXIF, on by default).
     *
     * @return bool
     */
    protected function rewrites_jpeg(): bool {
        return class_exists('\\core_files\\redactor\\manager') && get_config('core', 'file_redactor_exifremoverenabled');
    }

    public function test_a_jpeg_in_the_container_arrives_whole_with_or_without_exif(): void {
        if (class_exists('\\core_files\\redactor\\manager')) {
            set_config('file_redactor_exifremoverenabled', 1);
        }
        foreach (['content', 'intro', 'summary'] as $i => $field) {
            $cases = ['without exif' => $this->image('jpg', 40 + $i), 'with exif' => $this->jpeg_with_exif(50 + $i)];
            foreach ($cases as $case => $bytes) {
                // Bare, the JPEG Moodle rewrote does not match its digest: refused (why the container exists).
                $bare = $this->uploaded($bytes, false);
                $changed = $this->draft_changed($bare);
                if ($this->rewrites_jpeg() && $case === 'with exif') {
                    $this->assertTrue($changed, 'Moodle rewrites a JPEG with EXIF: ' . $field);
                }
                if ($changed) {
                    $this->assert_refused($this->ops($field, [$bare]), $field . ', bare, ' . $case);
                }
                $file = $this->uploaded($bytes, true);
                $preview = $this->preview($this->ops($field, [$file]));
                $this->assertSame(restorer::STATUS_FINISHED, (int) $this->apply($preview)->status, $field . ', ' . $case);
                [$context, $component, $area, $itemid] = $this->area($field);
                $stored = get_file_storage()->get_file($context, $component, $area, $itemid, '/', $file['filename']);
                $this->assertNotFalse($stored, $field . ', ' . $case);
                $this->assertSame('image/jpeg', $stored->get_mimetype());
                $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored->get_content())[2]);
                $this->assertTrue(update_page_files::is_same($stored, $file), 'written from the bytes sent: ' . $field);
                // The privacy of the site applies to the file written: rewritten exactly when a bare upload is.
                $rewritten = !hash_equals($file['sha256'], hash('sha256', $stored->get_content()));
                $this->assertSame($changed, $rewritten, $field . ', ' . $case);
                $this->assertStringContainsString($file['filename'], $this->kept($field));
                // Sent again with another text: the file there is the same one (even rewritten), not a conflict.
                $again = $this->uploaded($bytes, true);
                $preview = $this->preview($this->ops($field, [$again], $this->citing([$file]) . '<p>Again ' . $case . '</p>'));
                $this->assertSame(restorer::STATUS_FINISHED, (int) $this->apply($preview)->status, $field . ', again, ' . $case);
                $this->assertCount(1, array_filter(
                    get_file_storage()->get_area_files($context, $component, $area, $itemid, 'id', false),
                    fn(\stored_file $f): bool => $f->get_filename() === $file['filename']
                ));
            }
        }
    }

    /**
     * Whether the bytes in a draft are not the ones sent (Moodle rewrote them).
     *
     * @param array $file
     * @return bool
     */
    protected function draft_changed(array $file): bool {
        $stored = restorer::draft_file($file['draftitemid'], $file['filename']);
        return !hash_equals($file['sha256'], hash('sha256', $stored->get_content()));
    }

    public function test_a_container_without_its_header_or_with_other_bytes_is_refused(): void {
        $bytes = $this->jpeg_with_exif();
        $other = $this->image('jpg', 9);
        foreach (['content', 'intro', 'summary'] as $field) {
            $this->assert_refused($this->ops($field, [$this->uploaded($bytes, true, '')]), $field . ': no header');
            $this->assert_refused($this->ops($field, [$this->uploaded($bytes, true, "MIWLABFILE2\n")]), $field . ': other header');
            $this->assert_refused($this->ops($field, [$this->uploaded($bytes, true, null, $other)]), $field . ': other bytes');
            $longer = $this->uploaded($bytes, true, null, $bytes . 'x');
            $this->assert_refused($this->ops($field, [$longer]), $field . ': one byte more');
        }
    }

    public function test_after_a_restore_a_webp_by_digest_gets_its_real_type(): void {
        $webp = $this->image('webp');
        $put = function (string $field, string $name, string $bytes): int {
            [$context, $component, $area, $itemid] = $this->area($field);
            return (int) get_file_storage()->create_file_from_string(['contextid' => $context, 'component' => $component,
                'filearea' => $area, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $name,
                'mimetype' => 'document/unknown'], $bytes)->get_id();
        };
        $real = [];
        foreach (['content', 'intro', 'summary'] as $field) {
            $real[] = $put($field, 'miwlab-' . hash('sha256', $webp) . '.webp', $webp);
        }
        // CONTROLS: bytes that are not a WebP under the name, and a WebP under another name, are not touched.
        $fake = $put('content', 'miwlab-' . str_repeat('a', 64) . '.webp', 'not an image');
        $other = $put('intro', 'figura.webp', $webp);
        $elsewhere = get_file_storage()->create_file_from_string(['contextid' => \context_course::instance(
            $this->getDataGenerator()->create_course()->id
        )->id, 'component' => 'course', 'filearea' => 'summary', 'itemid' => 0, 'filepath' => '/',
            'filename' => 'miwlab-' . hash('sha256', $webp) . '.webp', 'mimetype' => 'document/unknown'], $webp)->get_id();
        $this->assertSame(3, update_page_files::give_webp_its_type((int) $this->course->id));
        $mime = fn(int $id): string => get_file_storage()->get_file_by_id($id)->get_mimetype();
        foreach ($real as $id) {
            $this->assertSame('image/webp', $mime($id));
        }
        $this->assertSame('document/unknown', $mime($fake));
        $this->assertSame('document/unknown', $mime($other));
        $this->assertSame('document/unknown', $mime((int) $elsewhere), 'another course is not touched');
        $this->assertSame(0, update_page_files::give_webp_its_type((int) $this->course->id), 'again: nothing to do');
    }

    public function test_get_info_announces_it(): void {
        $this->assertContains('updatehashedfiles', \local_miwlab\external\get_info::execute()['features']);
    }

    public function test_the_three_areas_receive_the_files_in_their_original_format(): void {
        foreach (['content' => 'png', 'intro' => 'jpg', 'summary' => 'gif'] as $field => $type) {
            $bytes = $this->image($type);
            $file = $this->draft($bytes, $type);
            $html = $this->citing([$file]);
            $preview = $this->preview($this->ops($field, [$file]));
            $this->assertSame('update', $preview['items'][0]['action'], $field);
            $this->assertSame(restorer::STATUS_FINISHED, (int) $this->apply($preview)->status, $field);
            $this->assertSame($html, $this->kept($field));
            [$context, $component, $area, $itemid] = $this->area($field);
            $stored = get_file_storage()->get_file($context, $component, $area, $itemid, '/', $file['filename']);
            $this->assertSame($bytes, $stored->get_content(), 'the same bytes: ' . $field);
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'][$type];
            $this->assertSame($mime, $stored->get_mimetype(), 'the real type, not the label of the draft: ' . $field);
        }
    }

    public function test_a_forged_name_is_refused(): void {
        $bytes = $this->image();
        $digest = hash('sha256', $bytes);
        $names = [
            'another digest in the name' => 'miwlab-' . str_repeat('a', 64) . '.png',
            'a path' => '../miwlab-' . $digest . '.png',
            'upper case' => 'miwlab-' . strtoupper($digest) . '.png',
            'two extensions' => 'miwlab-' . $digest . '.png.html',
            'svg' => 'miwlab-' . $digest . '.svg',
            'jpeg, which is jpg' => 'miwlab-' . $digest . '.jpeg',
            'another prefix' => 'other-' . $digest . '.png',
        ];
        foreach (['content', 'intro', 'summary'] as $field) {
            foreach ($names as $case => $name) {
                $this->assert_refused($this->ops($field, [$this->draft($bytes, 'png', $name)]), $field . ': ' . $case);
            }
        }
    }

    public function test_bytes_that_are_not_of_the_digest_or_of_the_type_are_refused(): void {
        $declared = $this->image('png', 1);
        $other = $this->image('png', 2);
        $digest = hash('sha256', $declared);
        $wrong = $this->draft($other, 'png', 'miwlab-' . $digest . '.png');
        $wrong['sha256'] = $digest;
        $cases = [
            'another content' => $wrong,
            'a png called jpg' => $this->draft($declared, 'jpg'),
            'not an image' => $this->draft('<script>alert(1)</script>', 'png'),
            'a wrong size' => ['size' => strlen($declared) + 1] + $this->draft($declared),
        ];
        foreach (['content', 'intro', 'summary'] as $field) {
            foreach ($cases as $case => $file) {
                $this->assert_refused($this->ops($field, [$file]), $field . ': ' . $case);
            }
        }
    }

    public function test_where_the_files_do_not_belong_they_are_refused(): void {
        $bytes = $this->image();
        $hashed = $this->draft($bytes);
        $numbered = $this->draft($bytes, 'png', 'imagem-1.png');
        $this->assert_refused($this->ops('intro', [$numbered]), 'a numbered image is only of the content of a Page');
        $this->assert_refused($this->ops('summary', [$numbered]), 'a numbered image in a section');
        $this->assert_refused($this->ops('content', [$hashed], '<p>cites nothing</p>'), 'not cited');
        $this->assert_refused(['sections' => [['id' => $this->section, 'files' => [$hashed]]]], 'files without the summary');
        $ops = $this->ops('content', [$hashed]);
        $ops['activities'][0]['cmid'] = (int) $this->assign->cmid;
        try {
            $this->preview($ops);
            $this->fail('content of what is not a Page');
        } catch (\moodle_exception $e) {
            $this->assertNotSame('', $e->errorcode);
        }
    }

    public function test_four_numbered_and_four_by_digest_fit_in_one_page(): void {
        $files = [];
        for ($i = 1; $i <= 4; $i++) {
            $files[] = $this->draft($this->image('png', $i), 'png', 'imagem-' . $i . '.png');
            $files[] = $this->draft($this->image('png', 10 + $i));
        }
        $this->assertSame('update', $this->preview($this->ops('content', $files))['items'][0]['action']);
        $five = [];
        for ($i = 1; $i <= 5; $i++) {
            $five[] = $this->draft($this->image('png', 20 + $i));
        }
        foreach (['content', 'intro', 'summary'] as $field) {
            $this->assert_refused($this->ops($field, $five), 'five by digest: ' . $field);
        }
    }

    public function test_a_file_there_with_other_bytes_is_never_overwritten(): void {
        foreach (['content', 'intro', 'summary'] as $field) {
            $file = $this->draft($this->image());
            [$context, $component, $area, $itemid] = $this->area($field);
            $there = get_file_storage()->create_file_from_string(['contextid' => $context, 'component' => $component,
                'filearea' => $area, 'itemid' => $itemid, 'filepath' => '/', 'filename' => $file['filename']], 'of the team');
            $this->assert_refused($this->ops($field, [$file]), $field);
            $this->assertSame('of the team', get_file_storage()->get_file_by_id($there->get_id())->get_content());
        }
    }

    public function test_without_the_file_the_text_stays_out_and_the_same_text_is_repaired_when_it_comes(): void {
        global $DB;
        $file = $this->draft($this->image());
        $html = $this->citing([$file]);
        $without = $this->preview(['sections' => [['id' => $this->section, 'summary' => $html]]]);
        $this->assertSame('newfile', $without['items'][0]['reason']);
        $DB->set_field('course_sections', 'summary', $html, ['id' => $this->section]);
        $preview = $this->preview($this->ops('summary', [$file]));
        $this->assertSame('update', $preview['items'][0]['action'], 'the text is the same and the file is missing');
        $this->apply($preview);
        [$context, $component, $area, $itemid] = $this->area('summary');
        $fs = get_file_storage();
        $this->assertTrue($fs->file_exists($context, $component, $area, $itemid, '/', $file['filename']));
        $this->assertSame([], $this->preview($this->ops('summary', [$file]))['items'], 'again: nothing to do');
    }

    public function test_a_failure_takes_the_files_and_the_texts_back(): void {
        $before = ['intro' => $this->kept('intro'), 'summary' => $this->kept('summary')];
        $intro = $this->draft($this->image('png', 1));
        $summary = $this->draft($this->image('png', 2));
        $preview = $this->preview($this->ops('intro', [$intro]) + $this->ops('summary', [$summary]));
        update_runner::$stephook = function (string $step): void {
            if ($step === 'finish') {
                throw new \RuntimeException('a failure at the end');
            }
        };
        $record = $this->apply($preview);
        $this->assertSame(restorer::STATUS_FAILED, (int) $record->status);
        $this->assertSame(1, (int) $record->rolledback);
        foreach (['intro' => $intro, 'summary' => $summary] as $field => $file) {
            $this->assertSame($before[$field], $this->kept($field));
            [$context, $component, $area, $itemid] = $this->area($field);
            $fs = get_file_storage();
            $this->assertFalse($fs->file_exists($context, $component, $area, $itemid, '/', $file['filename']));
        }
    }

    public function test_the_capability_to_update_is_needed_in_the_three_areas(): void {
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        foreach (['content', 'intro', 'summary'] as $field) {
            $file = $this->draft($this->image());
            try {
                preview_update::execute((int) $this->course->id, json_encode($this->ops($field, [$file])));
                $this->fail('without the capability: ' . $field);
            } catch (\moodle_exception $e) {
                $this->assertSame('error:missingcoursecapabilities', $e->errorcode);
            }
        }
    }

    public function test_webp_goes_to_every_version_with_its_real_type(): void {
        foreach (['content', 'intro', 'summary'] as $field) {
            $bytes = $this->image('webp');
            $file = $this->draft($bytes, 'webp');
            $preview = $this->preview($this->ops($field, [$file]));
            $this->assertSame('update', $preview['items'][0]['action'], $field);
            $this->assertSame(restorer::STATUS_FINISHED, (int) $this->apply($preview)->status, $field);
            [$context, $component, $area, $itemid] = $this->area($field);
            $stored = get_file_storage()->get_file($context, $component, $area, $itemid, '/', $file['filename']);
            $this->assertSame('image/webp', $stored->get_mimetype(), 'served as image/webp also where Moodle does not know it');
            $this->assertSame($bytes, $stored->get_content());
        }
        $this->assert_refused($this->ops('intro', [$this->draft($this->image('png'), 'webp')]), 'a png called webp');
    }
}
