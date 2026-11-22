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
 * The image of the course carried by the safe update (1.14.0, issue 355): replaced in place, only when its bytes differ from the
 * ones the course has, and put back by the journal when the update fails.
 *
 * The bytes travel like the images of a Page (the draft area of the account, checked by size, digest and signature, then kept
 * privately for the lifetime of the preview in the area of the page files, under the path of the course). The image goes to the
 * "overviewfiles" area of the course (the one Moodle shows in the list of courses) and, when the description of the course cites
 * it, to the "summary" area too, as the package of the MiwLab writes it.
 *
 * @package local_miwlab
 * @copyright 2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_course_image {
    /** File path of the staged image inside the private area of the preview. */
    const PATH = '/course/';

    /**
     * Validates the image before classification and returns its verified source file.
     *
     * @param \stdClass $course
     * @param array $ops
     * @param int|null $previewid Null reads the caller's draft, otherwise the private preview area.
     * @return array|null Entry with the metadata, the stored source and the canonical MIME type; null without an image.
     */
    public static function source(\stdClass $course, array $ops, ?int $previewid = null): ?array {
        $meta = $ops['coursesettings']['image'] ?? null;
        if (!$meta) {
            return null;
        }
        $source = $previewid === null ? restorer::draft_file($meta['draftitemid'], $meta['filename'])
            : get_file_storage()->get_file(
                \context_course::instance($course->id)->id,
                'local_miwlab',
                update_page_files::AREA,
                $previewid,
                self::PATH,
                $meta['filename']
            );
        if (!$source || $source->get_filepath() !== ($previewid === null ? '/' : self::PATH)) {
            throw new \moodle_exception('error:draftfilenotfound', 'local_miwlab');
        }
        return ['meta' => $meta, 'source' => $source, 'mimetype' => update_page_files::check_image($source, $meta)];
    }

    /**
     * Copies the verified bytes out of the mutable draft area.
     *
     * @param \stdClass $course
     * @param int $previewid
     * @param array|null $entry
     */
    public static function stage(\stdClass $course, int $previewid, ?array $entry): void {
        if (!$entry) {
            return;
        }
        get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_course::instance($course->id)->id, 'component' => 'local_miwlab',
            'filearea' => update_page_files::AREA, 'itemid' => $previewid, 'filepath' => self::PATH,
            'filename' => $entry['meta']['filename'], 'mimetype' => $entry['mimetype'],
        ], $entry['source']);
    }

    /**
     * The files of an area of the course (item 0).
     *
     * @param int $courseid
     * @param string $area overviewfiles or summary
     * @return \stored_file[]
     */
    protected static function area_files(int $courseid, string $area): array {
        return array_values(get_file_storage()->get_area_files(
            \context_course::instance($courseid)->id,
            'course',
            $area,
            0,
            'id',
            false
        ));
    }

    /**
     * Whether a stored file has exactly the bytes the application sent (the size first, so a large file is not read).
     *
     * @param \stored_file $file
     * @param array $meta
     * @return bool
     */
    protected static function same_bytes(\stored_file $file, array $meta): bool {
        return (int) $file->get_filesize() === $meta['size'] && hash_equals($meta['sha256'], hash('sha256', $file->get_content()));
    }

    /**
     * What the update has to do with the image: the image of the course is not the one sent, or the description that will be
     * there cites the image and the file of the description is missing or is another one.
     *
     * @param int $courseid
     * @param array $meta
     * @param string $summary The description the course will have after the update.
     * @return bool[] [the image of the course differs, the description needs the file]
     */
    public static function needs(int $courseid, array $meta, string $summary): array {
        $overview = true;
        foreach (self::area_files($courseid, 'overviewfiles') as $f) {
            if (self::same_bytes($f, $meta)) {
                $overview = false;
            }
        }
        $insummary = false;
        if (in_array($meta['filename'], update_classifier::cited_files($summary), true)) {
            $insummary = true;
            foreach (self::area_files($courseid, 'summary') as $f) {
                if ($f->get_filename() === $meta['filename'] && self::same_bytes($f, $meta)) {
                    $insummary = false;
                }
            }
        }
        return [$overview, $insummary];
    }

    /**
     * Puts the image in place; the journal keeps what is taken out and what is put in.
     *
     * @param \stdClass $course
     * @param array|null $entry
     * @param string $summary The description the course will have after the update.
     * @param update_journal $journal
     */
    public static function apply(\stdClass $course, ?array $entry, string $summary, update_journal $journal): void {
        if (!$entry) {
            return;
        }
        $meta = $entry['meta'];
        [$overview, $insummary] = self::needs((int) $course->id, $meta, $summary);
        $contextid = \context_course::instance($course->id)->id;
        $fs = get_file_storage();
        $put = function (string $area) use ($entry, $meta, $contextid, $fs, $journal): void {
            $file = $fs->create_file_from_storedfile([
                'contextid' => $contextid, 'component' => 'course', 'filearea' => $area, 'itemid' => 0, 'filepath' => '/',
                'filename' => $meta['filename'], 'mimetype' => $entry['mimetype'],
            ], $entry['source']);
            $journal->remember_created_file((int) $file->get_id());
        };
        if ($overview) {
            // The image of the list of courses is one: the ones there go (the journal keeps them) and this one takes the place.
            foreach (self::area_files((int) $course->id, 'overviewfiles') as $old) {
                $journal->remember_removed_file($old);
                $old->delete();
            }
            $put('overviewfiles');
        }
        if ($insummary) {
            foreach (self::area_files((int) $course->id, 'summary') as $old) {
                if ($old->get_filename() === $meta['filename']) {
                    $journal->remember_removed_file($old);
                    $old->delete();
                }
            }
            $put('summary');
        }
    }
}
