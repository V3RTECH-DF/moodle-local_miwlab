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

/**
 * Images accepted with a safe update, kept privately for the lifetime of its preview: in the content of a Page and, named by
 * their digest (1.15.0, issue 373), in the description of an activity, the summary of a section and the questions of a quiz.
 *
 * @package local_miwlab
 * @copyright 2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_page_files {
    /** Private file area for accepted preview bytes. */
    const AREA = 'updatepagefiles';
    /** Maximum decoded size of each image. */
    const MAX_FILE_BYTES = 2097152;
    /** Maximum total size in one preview. */
    const MAX_TOTAL_BYTES = 50331648;
    /** Most files in one preview. */
    const MAX_FILES = 24;
    /** Most files of each kind of name in one field. */
    const MAX_PER_FIELD = 4;
    /** The name of a figure of a Page (1.11.0). */
    const NUMBERED = '/^imagem-[1-4]\.(png|jpg|jpeg|gif)$/D';
    /**
     * The name of an image by its digest (1.15.0): the digest is the one of the bytes. WebP goes to every version: where Moodle
     * does not know the type (4.1 and 4.5), the file is stored with the real type and served as such (decision of 22/11/2026).
     */
    const HASHED = '/^miwlab-([a-f0-9]{64})\.(png|jpg|gif|webp)$/D';
    /**
     * The neutral container of an image (1.15.0, issues 355 and 373): Moodle rewrites a JPEG that arrives by
     * webservice/upload.php (4.5: every one; 5.x: the ones with EXIF), so the application sends "<name>.miwlab" with this
     * header before the exact bytes, and the checks are made on the bytes without it.
     */
    const CONTAINER = '.miwlab';
    /** The header of the container. */
    const HEADER = "MIWLABFILE1\n";
    /** The tag kept in the source of a file written from the application: the digest of the bytes it sent. */
    const SOURCE = 'miwlab:sha256:';

    /**
     * The fields of a list that bring files, each with where its files are kept and where they go.
     *
     * @param \stdClass $course
     * @param array $ops
     * @return array[] Each with item, cmid, dir, names (cited), files and target (null for the questions, which are written
     *   with their question).
     */
    protected static function fields(\stdClass $course, array $ops): array {
        $fields = [];
        foreach (($ops['activities'] ?? []) as $a) {
            $files = $a['text']['files'] ?? [];
            if (!$files) {
                continue;
            }
            $field = $a['text']['field'];
            $cm = get_coursemodule_from_id($field === 'content' ? 'page' : '', $a['cmid'], $course->id, false, MUST_EXIST);
            $fields[] = [
                'item' => 'a:' . $cm->id . ':text',
                'cmid' => (int) $cm->id,
                'dir' => '/' . $cm->id . '/',
                'names' => update_classifier::cited_files($a['text']['html']),
                'files' => $files,
                'target' => ['contextid' => \context_module::instance($cm->id)->id, 'component' => 'mod_' . $cm->modname,
                    'filearea' => $field, 'itemid' => 0],
            ];
        }
        foreach (($ops['sections'] ?? []) as $s) {
            if (empty($s['files'])) {
                continue;
            }
            $fields[] = [
                'item' => 's:' . $s['id'] . ':summary',
                'cmid' => 0,
                'dir' => '/s' . $s['id'] . '/',
                'names' => update_classifier::cited_files($s['summary']),
                'files' => $s['files'],
                'target' => ['contextid' => \context_course::instance($course->id)->id, 'component' => 'course',
                    'filearea' => 'section', 'itemid' => (int) $s['id']],
            ];
        }
        foreach (($ops['questions'] ?? []) as $q) {
            $names = question_list::cited_images($q['items']);
            $files = $q['files'] ?? [];
            if (array_diff($names, array_column($files, 'filename'))) {
                throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'question image without its file');
            }
            if ($files) {
                $fields[] = [
                    'item' => 'q:' . $q['cmid'],
                    'cmid' => (int) $q['cmid'],
                    'dir' => '/q' . $q['cmid'] . '/',
                    'names' => $names,
                    'files' => $files,
                    'target' => null,
                ];
            }
        }
        return $fields;
    }

    /**
     * Validates all images before classification and returns their verified source files.
     *
     * @param \stdClass $course
     * @param array $ops
     * @param int|null $previewid Null reads the caller's draft, otherwise the private preview area.
     * @return array[] Each entry contains item, cmid, dir, target, file metadata and the stored source.
     */
    public static function sources(\stdClass $course, array $ops, ?int $previewid = null): array {
        $sources = [];
        $total = 0;
        foreach (self::fields($course, $ops) as $field) {
            foreach ($field['files'] as $meta) {
                if (!in_array($meta['filename'], $field['names'], true)) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'uncited file');
                }
                if (count($sources) >= self::MAX_FILES) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'too many files');
                }
                $source = $previewid === null ? self::draft_file($meta['draftitemid'], $meta['filename'])
                    : self::staged_file($course, $previewid, $field['dir'], $meta['filename']);
                if (!$source || $source->get_filepath() !== ($previewid === null ? '/' : $field['dir'])) {
                    throw new \moodle_exception('error:draftfilenotfound', 'local_miwlab');
                }
                $mimetype = self::check_image($source, $meta);
                $total += $source->get_filesize();
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'files too large');
                }
                if ($field['target']) {
                    self::require_no_conflict($field['target'], $meta);
                }
                $sources[] = ['item' => $field['item'], 'cmid' => $field['cmid'], 'dir' => $field['dir'],
                    'target' => $field['target'], 'meta' => $meta, 'source' => $source, 'mimetype' => $mimetype];
            }
        }
        return $sources;
    }

    /**
     * The file that is in a field with this name, refused when it is another one: a file is never overwritten. It is the same
     * when it has the bytes sent or was written from them (the tag); a name by digest names its content, so an image of the
     * type of the name there is the same one even when Moodle rewrote it on restoring a package (privacy of the site).
     *
     * @param array $target
     * @param array $meta
     * @return bool Whether it is there already.
     */
    protected static function require_no_conflict(array $target, array $meta): bool {
        $existing = get_file_storage()->get_file(
            $target['contextid'],
            $target['component'],
            $target['filearea'],
            $target['itemid'],
            '/',
            $meta['filename']
        );
        if (!$existing) {
            return false;
        }
        $hashed = (bool) preg_match(self::HASHED, $meta['filename']);
        if (!self::is_same($existing, $meta) && !($hashed && self::is_image_of_its_name($existing))) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'file conflict');
        }
        return true;
    }

    /**
     * Whether a stored file is the image the application sent: written from it (the tag in its source, which survives the
     * rewriting of Moodle) or with exactly its bytes (the size first, so a large file is not read).
     *
     * @param \stored_file $file
     * @param array $meta
     * @return bool
     */
    public static function is_same(\stored_file $file, array $meta): bool {
        if (hash_equals(self::SOURCE . $meta['sha256'], (string) $file->get_source())) {
            return true;
        }
        return (int) $file->get_filesize() === $meta['size'] && hash_equals($meta['sha256'], hash('sha256', $file->get_content()));
    }

    /**
     * Whether a stored file is an image of the type its name says (read only up to the size an image of the application has).
     *
     * @param \stored_file $file
     * @return bool
     */
    protected static function is_image_of_its_name(\stored_file $file): bool {
        if ((int) $file->get_filesize() > 4 * self::MAX_FILE_BYTES) {
            return false;
        }
        $info = @getimagesizefromstring($file->get_content());
        $types = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF, 'webp' => IMAGETYPE_WEBP];
        return $info && ($types[strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION))] ?? null) === $info[2];
    }

    /**
     * The file of a name in a draft: the neutral container first, then the bare name (an application before 1.63.0).
     *
     * @param int $draftitemid
     * @param string $filename
     * @return \stored_file
     */
    public static function draft_file(int $draftitemid, string $filename): \stored_file {
        global $USER;
        $context = \context_user::instance($USER->id);
        $container = get_file_storage()->get_file($context->id, 'user', 'draft', $draftitemid, '/', $filename . self::CONTAINER);
        return restorer::draft_file($draftitemid, $container ? $filename . self::CONTAINER : $filename);
    }

    /**
     * The staged file of a name in the private area of a preview: the container first, then the bare name.
     *
     * @param \stdClass $course
     * @param int $previewid
     * @param string $dir
     * @param string $filename
     * @return \stored_file|null
     */
    public static function staged_file(\stdClass $course, int $previewid, string $dir, string $filename): ?\stored_file {
        $fs = get_file_storage();
        $contextid = \context_course::instance($course->id)->id;
        foreach ([$filename . self::CONTAINER, $filename] as $name) {
            $file = $fs->get_file($contextid, 'local_miwlab', self::AREA, $previewid, $dir, $name);
            if ($file) {
                return $file;
            }
        }
        return null;
    }

    /**
     * Whether a file is a neutral container.
     *
     * @param \stored_file $file
     * @return bool
     */
    public static function is_container(\stored_file $file): bool {
        return substr($file->get_filename(), -strlen(self::CONTAINER)) === self::CONTAINER;
    }

    /**
     * The bytes of the image in a file: without the header when it is a container (refused without the header).
     *
     * @param \stored_file $file
     * @return string
     */
    public static function bytes_of(\stored_file $file): string {
        if ((int) $file->get_filesize() > self::MAX_FILE_BYTES + strlen(self::HEADER)) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file size');
        }
        $content = $file->get_content();
        if (!self::is_container($file)) {
            return $content;
        }
        if (strncmp($content, self::HEADER, strlen(self::HEADER)) !== 0) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file container');
        }
        return substr($content, strlen(self::HEADER));
    }

    /**
     * Writes the image where it goes, tagged with the digest sent. From a container the bytes are written by Moodle as an
     * upload, so the removal of EXIF the site has on applies (privacy is not bypassed); a bare file is copied as it is.
     *
     * @param array $record The file record (context, component, area, item, path, name and MIME type).
     * @param array $entry An entry with meta and source.
     * @return \stored_file
     */
    public static function create(array $record, array $entry): \stored_file {
        $fs = get_file_storage();
        // In a draft Moodle keeps the source as a serialised object and stores its part "source" when it saves the area.
        $tag = self::SOURCE . $entry['meta']['sha256'];
        $record['source'] = ($record['filearea'] ?? '') === 'draft' ? serialize((object) ['source' => $tag]) : $tag;
        if (self::is_container($entry['source'])) {
            // From a path, as an upload: create_file_from_string sets the MIME type only after the content is stored, so the
            // removal of EXIF of the site would not see it.
            $path = make_request_directory() . '/image';
            file_put_contents($path, self::bytes_of($entry['source']));
            return $fs->create_file_from_pathname($record, $path);
        }
        return $fs->create_file_from_storedfile($record, $entry['source']);
    }

    /**
     * Checks size, digest and image signature, never trusting the draft MIME label.
     *
     * @param \stored_file $file
     * @param array $meta
     * @return string Canonical MIME type obtained from the accepted image signature.
     */
    public static function check_image(\stored_file $file, array $meta): string {
        $bytes = self::bytes_of($file);
        if (strlen($bytes) !== $meta['size'] || strlen($bytes) > self::MAX_FILE_BYTES) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file size');
        }
        $info = @getimagesizefromstring($bytes);
        $types = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF,
            'webp' => IMAGETYPE_WEBP];
        $extension = strtolower(pathinfo($meta['filename'], PATHINFO_EXTENSION));
        if (
            !hash_equals($meta['sha256'], hash('sha256', $bytes)) || !$info
            || ($types[$extension] ?? null) !== $info[2] || $info[0] * $info[1] > 16777216
        ) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file content');
        }
        return image_type_to_mime_type($info[2]);
    }

    /**
     * Copies verified bytes out of the mutable draft area.
     *
     * @param \stdClass $course
     * @param int $previewid
     * @param array $sources
     */
    public static function stage(\stdClass $course, int $previewid, array $sources): void {
        $fs = get_file_storage();
        foreach ($sources as $entry) {
            $fs->create_file_from_storedfile([
                'contextid' => \context_course::instance($course->id)->id, 'component' => 'local_miwlab',
                'filearea' => self::AREA, 'itemid' => $previewid, 'filepath' => $entry['dir'],
                'filename' => $entry['source']->get_filename(),
                'mimetype' => self::staged_mimetype($entry),
            ], $entry['source']);
        }
    }

    /**
     * Gives the WebP images named by their digest in a course their real type after a restore: Moodle 4.1 and 4.5 do not know the
     * extension and the restore does not keep the type of the package, so they would be served as unknown files. Only a file
     * whose bytes have the signature of a WebP (RIFF....WEBP) is changed, in the course and in its modules.
     *
     * @param int $courseid
     * @return int How many files were changed.
     */
    public static function give_webp_its_type(int $courseid): int {
        global $DB;
        $context = \context_course::instance($courseid);
        $files = $DB->get_records_select(
            'files',
            "filename LIKE 'miwlab-%.webp' AND mimetype <> 'image/webp'
             AND contextid IN (SELECT id FROM {context} WHERE id = :course OR " . $DB->sql_like('path', ':path') . ')',
            ['course' => $context->id, 'path' => $context->path . '/%'],
            'id',
            'id, filename'
        );
        $fs = get_file_storage();
        $changed = 0;
        foreach ($files as $record) {
            if (!preg_match(self::HASHED, $record->filename)) {
                continue;
            }
            $file = $fs->get_file_by_id($record->id);
            $handle = $file ? $file->get_content_file_handle() : false;
            $head = $handle ? (string) fread($handle, 12) : '';
            if ($handle) {
                fclose($handle);
            }
            if (strlen($head) === 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
                $DB->set_field('files', 'mimetype', 'image/webp', ['id' => $record->id]);
                $changed++;
            }
        }
        return $changed;
    }

    /**
     * The MIME type of the staged copy: a container keeps a neutral one, so nothing reads it as an image.
     *
     * @param array $entry
     * @return string
     */
    public static function staged_mimetype(array $entry): string {
        return self::is_container($entry['source']) ? 'application/octet-stream' : $entry['mimetype'];
    }

    /**
     * Adds new files only for the text operations the reconciled plan actually applies. The files of the questions go with
     * their question, in its transaction.
     *
     * @param array $sources
     * @param array $plan
     * @param update_journal $journal
     */
    public static function apply(array $sources, array $plan, update_journal $journal): void {
        foreach ($sources as $entry) {
            if (!$entry['target'] || !isset($plan['apply'][$entry['item']])) {
                continue;
            }
            if (self::require_no_conflict($entry['target'], $entry['meta'])) {
                continue;
            }
            $file = self::create($entry['target'] + [
                'filepath' => '/',
                'filename' => $entry['meta']['filename'],
                'mimetype' => $entry['mimetype'],
            ], $entry);
            $journal->remember_created_file((int) $file->get_id());
        }
    }

    /**
     * The verified images of the questions of one quiz, to write with them.
     *
     * @param array $sources
     * @param int $cmid
     * @return array name => entry
     */
    public static function of_questions(array $sources, int $cmid): array {
        $images = [];
        foreach ($sources as $entry) {
            if (!$entry['target'] && $entry['cmid'] === $cmid) {
                $images[$entry['meta']['filename']] = $entry;
            }
        }
        return $images;
    }
}
