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

use stdClass;

/**
 * The full backups (with the people) made before a replacement that discards progress (1.9.0, issue 204): the record of each one
 * and its deletion when the days the organisation chose are over.
 *
 * The file lives only in the course backup area (Course > Restore > Course backup area), where whoever administers the Moodle
 * restores it. The table holds no person: the course, the name and hash of the file, and the dates. Only the file this table
 * registered is ever deleted: a file of the same name that somebody put there afterwards (another content hash) stays.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fullcopies {
    /** The fewest days a full backup is kept. */
    const MIN_DAYS = 30;
    /** The most days a full backup is kept. */
    const MAX_DAYS = 365;
    /** Days a full backup is kept when the call does not say. */
    const DEFAULT_DAYS = 180;

    /**
     * Refuses a number of days outside the range.
     *
     * @param int $days
     * @return int The same number.
     * @throws \moodle_exception error:invalidkeepdays
     */
    public static function require_valid_days(int $days): int {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new \moodle_exception('error:invalidkeepdays', 'local_miwlab', '', (object) ['min' => self::MIN_DAYS,
                'max' => self::MAX_DAYS]);
        }
        return $days;
    }

    /**
     * Records a full backup that is now in the course backup area.
     *
     * @param int $courseid
     * @param \stored_file $file The file in the course backup area.
     * @param int $expires When it is deleted (Unix time).
     * @return int The id of the record.
     */
    public static function register(int $courseid, \stored_file $file, int $expires): int {
        global $DB;
        $record = $DB->get_record('local_miwlab_fullcopies', ['courseid' => $courseid, 'filename' => $file->get_filename()]);
        $fields = ['courseid' => $courseid, 'filename' => $file->get_filename(), 'contenthash' => $file->get_contenthash(),
            'timecreated' => time(), 'timeexpires' => $expires];
        if ($record) {
            $fields['id'] = $record->id;
            $DB->update_record('local_miwlab_fullcopies', (object) $fields);
            return (int) $record->id;
        }
        return (int) $DB->insert_record('local_miwlab_fullcopies', (object) $fields);
    }

    /**
     * Deletes the full backups whose time is over, and their records. A record whose file is gone (somebody deleted it, or the
     * course) only loses the record; a file with another content than the registered one is not touched.
     *
     * @param int|null $now For the tests; null: now.
     * @return int How many files were deleted.
     */
    public static function cleanup(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        $deleted = 0;
        $expired = $DB->get_records_select('local_miwlab_fullcopies', 'timeexpires < :now', ['now' => $now]);
        foreach ($expired as $record) {
            $context = \context_course::instance((int) $record->courseid, IGNORE_MISSING);
            if ($context) {
                $file = get_file_storage()->get_file($context->id, 'backup', 'course', 0, '/', $record->filename);
                if ($file && !$file->is_directory() && $file->get_contenthash() === $record->contenthash) {
                    $file->delete();
                    $deleted++;
                }
            }
            $DB->delete_records('local_miwlab_fullcopies', ['id' => $record->id]);
        }
        return $deleted;
    }

    /**
     * The record of a course backup file, if the plugin registered it.
     *
     * @param int $courseid
     * @param string $filename
     * @return stdClass|false
     */
    public static function record(int $courseid, string $filename) {
        global $DB;
        return $DB->get_record('local_miwlab_fullcopies', ['courseid' => $courseid, 'filename' => $filename]);
    }
}
