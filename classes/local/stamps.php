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
 * The stamp of the activities (1.10.0, issue 254): the ID number of a course module, `miwlab:<course>:a:<activity>`, with the
 * identifiers of the course and of the activity in the MiwLab.
 *
 * The ID number of an activity survives the copy of a course in Moodle (the course ID number does not), so the stamp is how the
 * MiwLab recognises a copy of one of its courses. This class writes it into courses that were already in Moodle, never over a
 * value that is already there (the organisation may have put its own), and finds the courses that carry it.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stamps {
    /** Pattern of the stamp. Together with the prefix it is at most 135 characters in theory, so the length is checked too. */
    const PATTERN = '/^miwlab:([A-Za-z0-9_-]{1,60}):a:([A-Za-z0-9_-]{1,60})$/';

    /** Pattern of the identifier of a course of the MiwLab. */
    const COURSE_PATTERN = '/^[A-Za-z0-9_-]{1,60}$/';

    /** The ID number of an activity has 100 characters. */
    const MAX_LENGTH = 100;

    /** Most activities in one call. */
    const MAX_ITEMS = 2000;

    /** Most courses returned by a search. */
    const MAX_COURSES = 100;

    /**
     * Whether a stamp is acceptable: the format, and fitting in the field.
     *
     * @param string $stamp
     * @return bool
     */
    public static function valid(string $stamp): bool {
        return strlen($stamp) <= self::MAX_LENGTH && (bool) preg_match(self::PATTERN, $stamp);
    }

    /**
     * Whether an identifier of a course of the MiwLab is acceptable.
     *
     * @param string $id
     * @return bool
     */
    public static function valid_course(string $id): bool {
        return (bool) preg_match(self::COURSE_PATTERN, $id);
    }

    /**
     * Writes the stamps into the activities of a course, never over a value that is there.
     *
     * Each item is decided on its own: written, or skipped with the reason (`invalidstamp`, `notincourse`, `deleting`, `existing`:
     * the activity already has another ID number, `same`: it already has this stamp, `duplicated`: the stamp is already in
     * another activity of the course or repeated in the list, `idnumbertaken`: a grade item of the course already uses it).
     * An activity named twice is decided at the first time.
     *
     * @param stdClass $course
     * @param array[] $items Each [cmid, stamp].
     * @return array [written => [[cmid, stamp]], skipped => [[cmid, stamp, reason, current]]]
     */
    public static function stamp(stdClass $course, array $items): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $written = [];
        $skipped = [];
        $cms = $DB->get_records('course_modules', ['course' => $course->id], '', 'id, idnumber, deletioninprogress');
        $used = [];
        foreach ($cms as $cm) {
            if ((string) $cm->idnumber !== '') {
                $used[(string) $cm->idnumber] = true;
            }
        }
        $seen = [];
        $skip = function (int $cmid, string $stamp, string $reason, string $current = '') use (&$skipped): void {
            $skipped[] = ['cmid' => $cmid, 'stamp' => $stamp, 'reason' => $reason, 'current' => $current];
        };
        foreach ($items as $item) {
            $cmid = (int) $item['cmid'];
            $stamp = (string) $item['stamp'];
            if (isset($seen[$cmid])) {
                $skip($cmid, $stamp, 'duplicated');
                continue;
            }
            $seen[$cmid] = true;
            if (!self::valid($stamp)) {
                $skip($cmid, $stamp, 'invalidstamp');
                continue;
            }
            if (!isset($cms[$cmid])) {
                $skip($cmid, $stamp, 'notincourse');
                continue;
            }
            $cm = $cms[$cmid];
            $current = (string) $cm->idnumber;
            if (!empty($cm->deletioninprogress)) {
                $skip($cmid, $stamp, 'deleting', $current);
            } else if ($current === $stamp) {
                $skip($cmid, $stamp, 'same', $current);
            } else if ($current !== '') {
                // The organisation's own value (or another stamp) is never overwritten.
                $skip($cmid, $stamp, 'existing', $current);
            } else if (isset($used[$stamp])) {
                $skip($cmid, $stamp, 'duplicated');
            } else if (!grade_verify_idnumber($stamp, $course->id)) {
                $skip($cmid, $stamp, 'idnumbertaken');
            } else {
                // Only where it is still empty: somebody may have typed one between the read and the write.
                $DB->set_field_select(
                    'course_modules',
                    'idnumber',
                    $stamp,
                    "id = :id AND (idnumber IS NULL OR idnumber = '')",
                    ['id' => $cmid]
                );
                if ((string) $DB->get_field('course_modules', 'idnumber', ['id' => $cmid]) !== $stamp) {
                    $skip($cmid, $stamp, 'existing', (string) $DB->get_field('course_modules', 'idnumber', ['id' => $cmid]));
                    continue;
                }
                \course_modinfo::purge_course_module_cache($course->id, $cmid);
                $used[$stamp] = true;
                $written[] = ['cmid' => $cmid, 'stamp' => $stamp];
            }
        }
        if ($written) {
            rebuild_course_cache($course->id, false, true);
        }
        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * The courses of the site that carry stamps of a course of the MiwLab, with how many activities and which.
     *
     * @param string $miwlabcourse Identifier of the course in the MiwLab.
     * @param bool $includestamps Whether to list the stamped activities of each course.
     * @return array [courses => [...], total => int, truncated => bool]; only the courses the account can read the reports of.
     */
    public static function find_courses(string $miwlabcourse, bool $includestamps): array {
        global $DB;
        $prefix = 'miwlab:' . $miwlabcourse . ':a:';
        $like = $DB->sql_like('cm.idnumber', ':prefix', true, true, false, '|');
        $rows = $DB->get_records_sql(
            "SELECT cm.id, cm.course, cm.idnumber
               FROM {course_modules} cm
              WHERE $like AND cm.deletioninprogress = 0
           ORDER BY cm.course, cm.id",
            ['prefix' => $DB->sql_like_escape($prefix, '|') . '%']
        );
        $bycourse = [];
        foreach ($rows as $row) {
            // The LIKE is case-insensitive in some databases; the identifiers are not.
            if (strncmp((string) $row->idnumber, $prefix, strlen($prefix)) !== 0 || !self::valid((string) $row->idnumber)) {
                continue;
            }
            $bycourse[(int) $row->course][] = ['cmid' => (int) $row->id, 'stamp' => (string) $row->idnumber];
        }
        $marker = 'miwlab:' . $miwlabcourse;
        $courses = [];
        foreach ($bycourse as $courseid => $stamped) {
            if ($courseid === (int) SITEID) {
                continue;
            }
            $course = $DB->get_record(
                'course',
                ['id' => $courseid],
                'id, category, fullname, shortname, idnumber, visible, timecreated'
            );
            if (!$course) {
                continue;
            }
            if (!has_capability('local/miwlab:viewreports', \context_course::instance($course->id))) {
                continue;
            }
            $courses[] = [
                'id' => (int) $course->id,
                'categoryid' => (int) $course->category,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'idnumber' => (string) $course->idnumber,
                'marked' => (string) $course->idnumber === $marker ||
                    strncmp((string) $course->idnumber, $marker . ':', strlen($marker) + 1) === 0,
                'visible' => (bool) $course->visible,
                'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                'timecreated' => (int) $course->timecreated,
                'stampedcount' => count($stamped),
                'stamps' => $includestamps ? $stamped : [],
            ];
        }
        $total = count($courses);
        return ['courses' => array_slice($courses, 0, self::MAX_COURSES), 'total' => $total,
            'truncated' => $total > self::MAX_COURSES];
    }
}
