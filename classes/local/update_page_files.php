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
 * Images accepted with a safe Page update, kept privately for the lifetime of its preview.
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

    /**
     * Validates all images before classification and returns their verified source files.
     *
     * @param \stdClass $course
     * @param array $ops
     * @param int|null $previewid Null reads the caller's draft, otherwise the private preview area.
     * @return array[] Each entry contains cmid, file metadata and the stored source.
     */
    public static function sources(\stdClass $course, array $ops, ?int $previewid = null): array {
        $fs = get_file_storage();
        $sources = [];
        $total = 0;
        foreach ($ops['activities'] as $a) {
            $files = $a['text']['files'] ?? [];
            if (!$files) {
                continue;
            }
            $cm = get_coursemodule_from_id('page', $a['cmid'], $course->id, false, MUST_EXIST);
            if ($a['text']['field'] !== 'content') {
                throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page files need content');
            }
            $names = update_classifier::cited_files($a['text']['html']);
            foreach ($files as $meta) {
                if (count($sources) >= 24) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'too many page files');
                }
                if (!in_array($meta['filename'], $names, true)) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'uncited page file');
                }
                $source = $previewid === null ? restorer::draft_file($meta['draftitemid'], $meta['filename'])
                    : $fs->get_file(
                        \context_course::instance($course->id)->id,
                        'local_miwlab',
                        self::AREA,
                        $previewid,
                        '/' . $cm->id . '/',
                        $meta['filename']
                    );
                if (!$source || $source->get_filepath() !== ($previewid === null ? '/' : '/' . $cm->id . '/')) {
                    throw new \moodle_exception('error:draftfilenotfound', 'local_miwlab');
                }
                $mimetype = self::check_image($source, $meta);
                $total += $source->get_filesize();
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page files too large');
                }
                $existing = $fs->get_file(
                    \context_module::instance($cm->id)->id,
                    'mod_page',
                    'content',
                    0,
                    '/',
                    $meta['filename']
                );
                if ($existing && $existing->get_contenthash() !== $source->get_contenthash()) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file conflict');
                }
                $sources[] = ['cmid' => (int) $cm->id, 'meta' => $meta, 'source' => $source, 'mimetype' => $mimetype];
            }
        }
        return $sources;
    }

    /**
     * Checks size, digest and image signature, never trusting the draft MIME label.
     *
     * @param \stored_file $file
     * @param array $meta
     * @return string Canonical MIME type obtained from the accepted image signature.
     */
    public static function check_image(\stored_file $file, array $meta): string {
        if ((int) $file->get_filesize() !== $meta['size'] || (int) $file->get_filesize() > self::MAX_FILE_BYTES) {
            throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file size');
        }
        $bytes = $file->get_content();
        $info = @getimagesizefromstring($bytes);
        $types = ['png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG, 'gif' => IMAGETYPE_GIF];
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
                'filearea' => self::AREA, 'itemid' => $previewid, 'filepath' => '/' . $entry['cmid'] . '/',
                'filename' => $entry['meta']['filename'],
                'mimetype' => $entry['mimetype'],
            ], $entry['source']);
        }
    }

    /**
     * Adds new content files only for text operations the reconciled plan actually applies.
     *
     * @param array $sources
     * @param array $plan
     * @param update_journal $journal
     */
    public static function apply(array $sources, array $plan, update_journal $journal): void {
        $allowed = $plan['apply'];
        $fs = get_file_storage();
        foreach ($sources as $entry) {
            $cmid = $entry['cmid'];
            if (!isset($allowed['a:' . $cmid . ':text'])) {
                continue;
            }
            $contextid = \context_module::instance($cmid)->id;
            $existing = $fs->get_file($contextid, 'mod_page', 'content', 0, '/', $entry['meta']['filename']);
            if ($existing) {
                if ($existing->get_contenthash() !== $entry['source']->get_contenthash()) {
                    throw new \moodle_exception('error:invalidoperations', 'local_miwlab', '', 'page file conflict');
                }
                continue;
            }
            $file = $fs->create_file_from_storedfile([
                'contextid' => $contextid, 'component' => 'mod_page', 'filearea' => 'content', 'itemid' => 0,
                'filepath' => '/', 'filename' => $entry['meta']['filename'],
                'mimetype' => $entry['mimetype'],
            ], $entry['source']);
            $journal->remember_created_file((int) $file->get_id());
        }
    }
}
