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
 * Consolidated activity completion of a course: how many students completed each activity and each section.
 *
 * Only counts leave this class; the queries aggregate in the database, so not even the ids of the people are read.
 * A student is an active enrolled user with a role of the student archetype in the course (or above it).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class completion_report {
    /**
     * Builds the report of a course.
     *
     * @param stdClass $course
     * @return array
     */
    public static function build(stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $context = \context_course::instance($course->id);
        [$studentsql, $studentparams] = self::students_sql($context);
        $students = (int) $DB->count_records_sql("SELECT COUNT(1) FROM ($studentsql) st", $studentparams);

        $completion = new \completion_info($course);
        $enabled = (bool) $completion->is_enabled();
        $modinfo = get_fast_modinfo($course);

        $tracked = [];
        if ($enabled) {
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->completion != COMPLETION_TRACKING_NONE && empty($cm->deletioninprogress)) {
                    $tracked[$cm->id] = $cm;
                }
            }
        }

        $counts = [];
        $sectiondone = [];
        if ($tracked) {
            [$cmsql, $cmparams] = $DB->get_in_or_equal(array_keys($tracked), SQL_PARAMS_NAMED, 'cm');
            $rs = $DB->get_recordset_sql(
                "SELECT cmc.coursemoduleid AS cmid, cmc.completionstate AS state, COUNT(1) AS total
                   FROM {course_modules_completion} cmc
                  WHERE cmc.coursemoduleid $cmsql
                    AND cmc.userid IN (SELECT st.id FROM ($studentsql) st)
               GROUP BY cmc.coursemoduleid, cmc.completionstate",
                $cmparams + $studentparams
            );
            foreach ($rs as $row) {
                $counts[(int) $row->cmid][(int) $row->state] = (int) $row->total;
            }
            $rs->close();

            // A student completed a section when every tracked activity of it is complete (or complete with a pass grade).
            [$cmsql2, $cmparams2] = $DB->get_in_or_equal(array_keys($tracked), SQL_PARAMS_NAMED, 'cmt');
            [$cmsql3, $cmparams3] = $DB->get_in_or_equal(array_keys($tracked), SQL_PARAMS_NAMED, 'cms');
            $rs = $DB->get_recordset_sql(
                "SELECT done.section, COUNT(1) AS total
                   FROM (SELECT cm.section, cmc.userid, COUNT(1) AS completed
                           FROM {course_modules_completion} cmc
                           JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                          WHERE cm.id $cmsql2
                            AND cmc.completionstate IN (:complete, :completepass)
                            AND cmc.userid IN (SELECT st.id FROM ($studentsql) st)
                       GROUP BY cm.section, cmc.userid) done
                   JOIN (SELECT cm.section, COUNT(1) AS activities
                           FROM {course_modules} cm
                          WHERE cm.id $cmsql3
                       GROUP BY cm.section) sec ON sec.section = done.section AND sec.activities = done.completed
               GROUP BY done.section",
                $cmparams2 + $cmparams3 + $studentparams
                + ['complete' => COMPLETION_COMPLETE, 'completepass' => COMPLETION_COMPLETE_PASS]
            );
            foreach ($rs as $row) {
                $sectiondone[(int) $row->section] = (int) $row->total;
            }
            $rs->close();
        }

        $sections = [];
        foreach ($modinfo->get_section_info_all() as $section) {
            $activities = 0;
            foreach ($tracked as $cm) {
                if ((int) $cm->section === (int) $section->id) {
                    $activities++;
                }
            }
            $sections[] = [
                'id' => (int) $section->id,
                'number' => (int) $section->section,
                'name' => get_section_name($course, $section),
                'visible' => (bool) $section->visible,
                'activities' => $activities,
                'completed' => $activities ? ($sectiondone[(int) $section->id] ?? 0) : 0,
            ];
        }

        $activities = [];
        foreach ($tracked as $cm) {
            $states = $counts[$cm->id] ?? [];
            $activities[] = [
                'cmid' => (int) $cm->id,
                'sectionid' => (int) $cm->section,
                'sectionnumber' => (int) $cm->sectionnum,
                'modname' => $cm->modname,
                'name' => $cm->get_formatted_name(),
                'idnumber' => (string) $cm->idnumber,
                'visible' => (bool) $cm->visible,
                'completiontype' => (int) $cm->completion,
                'completed' => ($states[COMPLETION_COMPLETE] ?? 0) + ($states[COMPLETION_COMPLETE_PASS] ?? 0),
                'completedfail' => $states[COMPLETION_COMPLETE_FAIL] ?? 0,
            ];
        }

        $coursecompletion = ['enabled' => false, 'completed' => 0];
        if ($enabled && $completion->has_criteria()) {
            $coursecompletion = [
                'enabled' => true,
                'completed' => (int) $DB->count_records_sql(
                    "SELECT COUNT(1)
                       FROM {course_completions} cc
                      WHERE cc.course = :courseid AND cc.timecompleted IS NOT NULL
                        AND cc.userid IN (SELECT st.id FROM ($studentsql) st)",
                    ['courseid' => $course->id] + $studentparams
                ),
            ];
        }

        return [
            'courseid' => (int) $course->id,
            'completionenabled' => $enabled,
            'students' => $students,
            'coursecompletion' => $coursecompletion,
            'sections' => $sections,
            'activities' => $activities,
            'timegenerated' => time(),
        ];
    }

    /**
     * SQL of the students of the course: active enrolment and a role of the student archetype in the course or above.
     *
     * @param \context_course $context
     * @return array [sql returning one column "id", named params]
     */
    public static function students_sql(\context_course $context): array {
        global $DB;
        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, '', 0, true);
        $roleids = array_keys(get_archetype_roles('student'));
        if (!$roleids) {
            return ['SELECT u.id FROM {user} u WHERE 1 = 0', []];
        }
        [$rolesql, $roleparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'lmrole');
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'lmctx');
        $sql = "SELECT DISTINCT je.id
                  FROM ($enrolledsql) je
                  JOIN {role_assignments} ra ON ra.userid = je.id
                 WHERE ra.roleid $rolesql AND ra.contextid $ctxsql";
        return [$sql, $enrolledparams + $roleparams + $ctxparams];
    }
}
