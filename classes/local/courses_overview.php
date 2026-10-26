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

defined('MOODLE_INTERNAL') || die();

// The completion constants (COMPLETION_TRACKING_NONE...) live in this library, which a web service request has not
// loaded: the whole class needs it.
global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * The numbers of the courses of some categories, in batch: students, whether the course measures completion and how many completed.
 *
 * Only counts leave this class, and the queries aggregate in the database: not even the ids of the people are read. The rules
 * are those of {@see completion_report}, course by course, only computed for many courses in a few queries:
 *  - a student is an active enrolled user (enrolment and enrol instance active, inside its dates, account not deleted) who has a
 *    role of the student archetype in the course or above it (category or site);
 *  - the course measures completion when completion is on in the site and in the course and it has at least one activity with
 *    completion tracking (not being deleted) or completion criteria;
 *  - "completed" counts only students: by the completion of the course when it has criteria, and otherwise by all the tracked
 *    activities complete (or complete with a pass grade).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class courses_overview {
    /** Most courses in one call. */
    const MAX_PER_CALL = 500;

    /** Most categories in one call. */
    const MAX_CATEGORIES = 200;

    /**
     * The ids of the categories and, when asked, of all the ones below them.
     *
     * @param int[] $categoryids
     * @param bool $subcategories
     * @return int[]
     */
    public static function category_ids(array $categoryids, bool $subcategories): array {
        global $DB;
        $ids = array_map('intval', $categoryids);
        if (!$subcategories) {
            return array_values(array_unique($ids));
        }
        $all = $ids;
        foreach ($ids as $id) {
            $like = $DB->sql_like('path', ':path');
            $below = $DB->get_fieldset_select('course_categories', 'id', $like, ['path' => '%/' . $id . '/%']);
            foreach ($below as $child) {
                $all[] = (int) $child;
            }
        }
        return array_values(array_unique($all));
    }

    /**
     * The courses of the categories (the page asked) with their numbers.
     *
     * @param int[] $categoryids Every category whose own courses are listed (subcategories already expanded).
     * @param int $limitfrom
     * @param int $limitnum
     * @return array [total, courses]
     */
    public static function build(array $categoryids, int $limitfrom, int $limitnum): array {
        global $CFG, $DB;
        if (!$categoryids) {
            return [0, []];
        }
        [$catsql, $catparams] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED, 'lmcat');
        $where = "category $catsql AND id <> :site";
        $params = $catparams + ['site' => SITEID];
        $total = (int) $DB->count_records_select('course', $where, $params);
        $records = $DB->get_records_select(
            'course',
            $where,
            $params,
            'id',
            'id, category, fullname, shortname, visible, enablecompletion',
            $limitfrom,
            $limitnum
        );
        if (!$records) {
            return [$total, []];
        }
        $ids = array_map('intval', array_keys($records));
        $students = self::students($ids);
        $tracked = self::tracked_activities($ids);
        $criteria = self::criteria($ids);
        $completedbycriteria = self::completed_by_criteria($ids);
        $completedbyactivities = self::completed_by_activities($ids);
        $sitecompletion = !empty($CFG->enablecompletion);

        $courses = [];
        foreach ($records as $course) {
            $id = (int) $course->id;
            $enabled = $sitecompletion && !empty($course->enablecompletion);
            $hastracked = ($tracked[$id] ?? 0) > 0;
            $hascriteria = $enabled && ($criteria[$id] ?? 0) > 0;
            $measures = $enabled && ($hastracked || $hascriteria);
            $basis = 'none';
            $completed = 0;
            if ($measures) {
                $basis = $hascriteria ? 'course' : 'activities';
                $completed = $hascriteria ? ($completedbycriteria[$id] ?? 0) : ($completedbyactivities[$id] ?? 0);
            }
            $courses[] = [
                'id' => $id,
                'categoryid' => (int) $course->category,
                'fullname' => $course->fullname,
                'shortname' => $course->shortname,
                'visible' => (bool) $course->visible,
                'students' => $students[$id] ?? 0,
                'completionenabled' => $enabled,
                'trackedactivities' => $enabled ? ($tracked[$id] ?? 0) : 0,
                'measures' => $measures,
                'completedby' => $basis,
                'completed' => $completed,
            ];
        }
        return [$total, $courses];
    }

    /**
     * SQL of the (course, user) pairs of the students of the given courses, the same rule as {@see completion_report::students_sql}
     * for each course, in one query: the enrolment is active as get_enrolled_sql() reads it, and the role of the student archetype
     * is held in the course context or in any context above it.
     *
     * @param int[] $courseids
     * @return array [sql returning columns "courseid" and "userid", named params]
     */
    protected static function pairs_sql(array $courseids): array {
        global $DB;
        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lmco');
        $roleids = array_keys(get_archetype_roles('student'));
        if (!$roleids) {
            return ['SELECT 0 AS courseid, 0 AS userid FROM {user} WHERE 1 = 0', []];
        }
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'lmro');
        $now = round(time(), -2);
        $sql = "SELECT DISTINCT e.courseid AS courseid, ue.userid AS userid
                  FROM {enrol} e
                  JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  JOIN {user} u ON u.id = ue.userid
                  JOIN {context} cx ON cx.instanceid = e.courseid AND cx.contextlevel = :lmlevel
                 WHERE e.courseid $coursesql
                   AND e.status = :lmenabled
                   AND ue.status = :lmactive
                   AND ue.timestart < :lmnow1 AND (ue.timeend = 0 OR ue.timeend > :lmnow2)
                   AND u.deleted = 0
                   AND EXISTS (SELECT 1
                                 FROM {role_assignments} ra
                                 JOIN {context} rc ON rc.id = ra.contextid
                                WHERE ra.userid = ue.userid AND ra.roleid $rolesql
                                  AND (rc.id = cx.id OR cx.path LIKE " . $DB->sql_concat('rc.path', "'/%'") . "))";
        $params = $courseparams + $roleparams + [
            'lmlevel' => CONTEXT_COURSE,
            'lmenabled' => ENROL_INSTANCE_ENABLED,
            'lmactive' => ENROL_USER_ACTIVE,
            'lmnow1' => $now,
            'lmnow2' => $now,
        ];
        return [$sql, $params];
    }

    /**
     * Students by course.
     *
     * @param int[] $courseids
     * @return int[] course id => students
     */
    protected static function students(array $courseids): array {
        global $DB;
        [$pairs, $params] = self::pairs_sql($courseids);
        $rs = $DB->get_recordset_sql("SELECT sp.courseid, COUNT(1) AS total FROM ($pairs) sp GROUP BY sp.courseid", $params);
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->courseid] = (int) $row->total;
        }
        $rs->close();
        return $result;
    }

    /**
     * Activities with completion tracking by course (the ones being deleted do not count, as in completion_report).
     *
     * @param int[] $courseids
     * @return int[]
     */
    protected static function tracked_activities(array $courseids): array {
        global $DB;
        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lmtr');
        $rs = $DB->get_recordset_sql(
            "SELECT cm.course AS courseid, COUNT(1) AS total
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course $coursesql AND cm.completion <> :none AND cm.deletioninprogress = 0
           GROUP BY cm.course",
            $params + ['none' => COMPLETION_TRACKING_NONE]
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->courseid] = (int) $row->total;
        }
        $rs->close();
        return $result;
    }

    /**
     * Completion criteria of the course, by course.
     *
     * @param int[] $courseids
     * @return int[]
     */
    protected static function criteria(array $courseids): array {
        global $DB;
        [$coursesql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lmcr');
        $rs = $DB->get_recordset_sql(
            "SELECT cr.course AS courseid, COUNT(1) AS total
               FROM {course_completion_criteria} cr
              WHERE cr.course $coursesql
           GROUP BY cr.course",
            $params
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->courseid] = (int) $row->total;
        }
        $rs->close();
        return $result;
    }

    /**
     * Students who completed the course (it has criteria), by course.
     *
     * @param int[] $courseids
     * @return int[]
     */
    protected static function completed_by_criteria(array $courseids): array {
        global $DB;
        [$pairs, $params] = self::pairs_sql($courseids);
        $rs = $DB->get_recordset_sql(
            "SELECT cc.course AS courseid, COUNT(1) AS total
               FROM {course_completions} cc
               JOIN ($pairs) sp ON sp.courseid = cc.course AND sp.userid = cc.userid
              WHERE cc.timecompleted IS NOT NULL
           GROUP BY cc.course",
            $params
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->courseid] = (int) $row->total;
        }
        $rs->close();
        return $result;
    }

    /**
     * Students who have every tracked activity complete (or complete with a pass grade), by course.
     *
     * @param int[] $courseids
     * @return int[]
     */
    protected static function completed_by_activities(array $courseids): array {
        global $DB;
        [$pairs, $params] = self::pairs_sql($courseids);
        [$coursesql, $courseparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'lmca');
        $rs = $DB->get_recordset_sql(
            "SELECT done.courseid, COUNT(1) AS total
               FROM (SELECT cm.course AS courseid, cmc.userid AS userid, COUNT(1) AS finished
                       FROM {course_modules_completion} cmc
                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                       JOIN {modules} m ON m.id = cm.module
                       JOIN ($pairs) sp ON sp.courseid = cm.course AND sp.userid = cmc.userid
                      WHERE cm.completion <> :none1 AND cm.deletioninprogress = 0
                        AND cmc.completionstate IN (:complete, :completepass)
                   GROUP BY cm.course, cmc.userid) done
               JOIN (SELECT cm2.course AS courseid, COUNT(1) AS tracked
                       FROM {course_modules} cm2
                       JOIN {modules} m2 ON m2.id = cm2.module
                      WHERE cm2.course $coursesql AND cm2.completion <> :none2 AND cm2.deletioninprogress = 0
                   GROUP BY cm2.course) trk ON trk.courseid = done.courseid AND trk.tracked = done.finished
           GROUP BY done.courseid",
            $params + $courseparams + [
                'none1' => COMPLETION_TRACKING_NONE,
                'none2' => COMPLETION_TRACKING_NONE,
                'complete' => COMPLETION_COMPLETE,
                'completepass' => COMPLETION_COMPLETE_PASS,
            ]
        );
        $result = [];
        foreach ($rs as $row) {
            $result[(int) $row->courseid] = (int) $row->total;
        }
        $rs->close();
        return $result;
    }
}
