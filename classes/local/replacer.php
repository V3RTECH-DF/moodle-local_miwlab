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

use backup;
use base_setting;
use moodle_exception;
use restore_controller;
use stdClass;

/**
 * Replaces the content of an existing course with a MiwLab package (1.5.0), in the background, after a backup of it.
 *
 * The course stays the same course: its id and address, full and short name, category, summary and image, format, dates,
 * visibility, tags, enrolments, roles and groups. What changes is its content: sections, activities and resources, blocks,
 * the gradebook and the question bank of the course come from the package, as Moodle's own "Restore into this course,
 * delete the contents first" does, without overwriting the course configuration.
 *
 * The safety rules, all checked here, on the server:
 * - a course where somebody has progress (an activity completed, a grade, a submission, an answer, a post, a note) is never
 *   replaced: the check runs when the replacement is asked and again, right before the content is deleted;
 * - a backup of the course, without user data, is made first; when it fails, nothing is replaced. It ends in the course
 *   backup area (Course > Restore), with the backups that were already there (Moodle empties that area when it deletes the
 *   content, so they are kept aside and put back);
 * - when the restore fails after the content was deleted, the backup is restored into the course again.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class replacer {
    /** Mode of a restore record that replaces a course (restorer::MODE_NEW creates one). */
    const MODE = 'replace';

    /**
     * Capabilities needed in the course, all of them, to replace its content: the plugin's own, the backup made before, the
     * restore into the course, its settings (keep enrolments, roles and groups) and its dates (keep the course start date),
     * and (1.6.0) deleting the badges of the old content and activating the ones the package brings.
     */
    const CAPABILITIES = [
        'local/miwlab:replacecourse',
        'moodle/backup:backupcourse',
        'moodle/restore:restorecourse',
        'moodle/restore:configure',
        'moodle/restore:rolldates',
        'moodle/badges:deletebadge',
        'moodle/badges:configurecriteria',
    ];

    /**
     * Capability needed in the course, besides CAPABILITIES, for the replacement that discards progress (1.9.0, issue 204): the
     * full backup carries the people. Without it that replacement is refused before anything is touched.
     */
    const FULL_BACKUP_CAPABILITIES = ['moodle/backup:userinfo'];

    /** Capabilities needed in the course to hide it. */
    const HIDE_CAPABILITIES = ['local/miwlab:hidecourse', 'moodle/course:visibility'];

    /** File area of the plugin, in the course context, where files are kept aside while the content is replaced. */
    const STASH_AREA = 'replacestash';

    /**
     * Only for PHPUnit (ignored otherwise): makes the backup instead of Moodle, given the course id, so that a failure of the
     * backup can be simulated. Returns the stored file, or throws.
     *
     * @var callable|null
     */
    public static $backuphook = null;

    /**
     * Only for PHPUnit (ignored otherwise): called with the course id right after Moodle restored the package, so that a
     * failure after the restore (the badges of the package already in the course) can be simulated.
     *
     * @var callable|null
     */
    public static $afterplanhook = null;

    /**
     * The course to replace or to hide: it must exist and must not be the site.
     *
     * @param int $courseid
     * @return stdClass
     * @throws moodle_exception
     */
    public static function course(int $courseid): stdClass {
        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course || (int) $course->id === (int) SITEID) {
            throw new moodle_exception('error:coursenotfound', 'local_miwlab');
        }
        return $course;
    }

    /**
     * The capabilities the current user lacks in a course.
     *
     * @param \context_course $context
     * @param string[] $capabilities
     * @return string[]
     */
    public static function missing_capabilities(\context_course $context, array $capabilities = self::CAPABILITIES): array {
        $missing = [];
        foreach ($capabilities as $capability) {
            if (!has_capability($capability, $context)) {
                $missing[] = $capability;
            }
        }
        return $missing;
    }

    /**
     * Refuses, naming every missing capability, when the current user cannot replace (or hide) the course.
     *
     * @param \context_course $context
     * @param stdClass $course
     * @param string[] $capabilities
     * @throws moodle_exception
     */
    public static function require_capabilities(
        \context_course $context,
        stdClass $course,
        array $capabilities = self::CAPABILITIES
    ): void {
        $missing = self::missing_capabilities($context, $capabilities);
        if ($missing) {
            throw new moodle_exception('error:missingcoursecapabilities', 'local_miwlab', '', (object) [
                'course' => format_string($course->fullname, true, ['context' => $context]),
                'capabilities' => implode(', ', $missing),
            ]);
        }
    }

    /**
     * The activity (course module id) of a module instance, as a SQL expression; 0 when there is none. Used by the progress queries
     * so that the same queries tell who has progress in the course and in which activity (1.9.0, issue 204).
     *
     * @param string $module Module name (assign, quiz...).
     * @param string $instance SQL expression of the instance id.
     * @return string
     */
    protected static function cm_of(string $module, string $instance): string {
        return "COALESCE((SELECT cmx.id FROM {course_modules} cmx JOIN {modules} mx ON mx.id = cmx.module
                           WHERE mx.name = '" . $module . "' AND cmx.instance = " . $instance . "), 0)";
    }

    /**
     * The queries that list who has progress in a course, each with the tables it needs (a module may be missing or, as the
     * chat and the survey from Moodle 5.0 on, gone). Each returns the columns "userid" and "cmid" (1.9.0: the activity where the
     * progress is; 0 when it belongs to the course as a whole: the course completion, the course total and manual items of the
     * gradebook, the badges and the competencies of the course, the notes); ":courseid" is the course.
     *
     * Progress is anything that deleting the content would destroy about a person: an activity completed, the course
     * completed, any grade (of any grade item, the course total included), a submission, a quiz or lesson attempt, an answer
     * (feedback, choice, survey, questionnaire, H5P, SCORM, database, external tool), a post, a glossary entry, a wiki page, a
     * workshop submission or assessment, a certificate or a course badge issued, a course competency rated or with evidence,
     * a meeting joined, an attendance taken, a note. What the queries do not cover is refused by KNOWN_MODULES.
     *
     * @return array[] [tables, sql]
     */
    protected static function progress_queries(): array {
        return [
            [['course_modules_completion'], "SELECT cmc.userid, cmc.coursemoduleid AS cmid FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid WHERE cm.course = :courseid AND cmc.completionstate > 0"],
            [['course_completions'], "SELECT userid, 0 AS cmid FROM {course_completions}
                WHERE course = :courseid AND (timecompleted IS NOT NULL OR reaggregate > 0)"],
            [['course_completion_crit_compl'],
                "SELECT userid, 0 AS cmid FROM {course_completion_crit_compl} WHERE course = :courseid"],
            // Any grade of any grade item of the course (activities, manual items, categories and the course total). The grade
            // of an
            // activity belongs to it; the others to the course.
            [['grade_grades'],
                "SELECT gg.userid, CASE WHEN gi.itemtype = 'mod' THEN COALESCE((SELECT cmx.id FROM {course_modules} cmx
                JOIN {modules} mx ON mx.id = cmx.module WHERE mx.name = gi.itemmodule AND cmx.instance = gi.iteminstance), 0)
                ELSE 0 END AS cmid
                FROM {grade_grades} gg JOIN {grade_items} gi ON gi.id = gg.itemid
                WHERE gi.courseid = :courseid AND (gg.finalgrade IS NOT NULL OR gg.rawgrade IS NOT NULL OR gg.overridden > 0)"],
            [['badge_issued', 'badge'], "SELECT bi.userid, 0 AS cmid FROM {badge_issued} bi JOIN {badge} b ON b.id = bi.badgeid
                WHERE b.courseid = :courseid"],
            [['competency_usercompcourse'], "SELECT userid, 0 AS cmid FROM {competency_usercompcourse}
                WHERE courseid = :courseid AND (grade IS NOT NULL OR proficiency IS NOT NULL)"],
            [['competency_evidence', 'competency_usercomp'], "SELECT uc.userid,
                    CASE WHEN ctx.contextlevel = " . CONTEXT_MODULE . " THEN ctx.instanceid ELSE 0 END AS cmid
                FROM {competency_evidence} e
                JOIN {competency_usercomp} uc ON uc.id = e.usercompetencyid JOIN {context} ctx ON ctx.id = e.contextid
                WHERE (ctx.contextlevel = " . CONTEXT_COURSE . " AND ctx.instanceid = :courseid)
                   OR (ctx.contextlevel = " . CONTEXT_MODULE . " AND ctx.instanceid IN
                       (SELECT cmx.id FROM {course_modules} cmx WHERE cmx.course = :courseid2))"],
            [['workshop_assessments', 'workshop_submissions'], "SELECT wa.reviewerid AS userid, " . self::cm_of(
                'workshop',
                'w.id'
            ) . " AS cmid
                FROM {workshop_assessments} wa
                JOIN {workshop_submissions} ws ON ws.id = wa.submissionid JOIN {workshop} w ON w.id = ws.workshopid
                WHERE w.course = :courseid AND ws.example = 0"],
            [['lti_submission'], "SELECT ls.userid, " . self::cm_of('lti', 'l.id') . " AS cmid FROM {lti_submission} ls
                JOIN {lti} l ON l.id = ls.ltiid WHERE l.course = :courseid"],
            [['bigbluebuttonbn_logs'], "SELECT bl.userid, " . self::cm_of('bigbluebuttonbn', 'bl.bigbluebuttonbnid') . " AS cmid
                FROM {bigbluebuttonbn_logs} bl WHERE bl.courseid = :courseid AND bl.log IN ('Join', 'Played')"],
            [['hvp_xapi_results', 'hvp'], "SELECT x.user_id AS userid, " . self::cm_of(
                'hvp',
                'h.id'
            ) . " AS cmid FROM {hvp_xapi_results} x
                JOIN {hvp} h ON h.id = x.content_id WHERE h.course = :courseid"],
            [['hvp_content_user_data', 'hvp'], "SELECT d.user_id AS userid, " . self::cm_of('hvp', 'h.id') . " AS cmid
                FROM {hvp_content_user_data} d JOIN {hvp} h ON h.id = d.hvp_id WHERE h.course = :courseid"],
            [['attendance_log', 'attendance_sessions'], "SELECT al.studentid AS userid, " . self::cm_of(
                'attendance',
                'a.id'
            ) . " AS cmid
                FROM {attendance_log} al
                JOIN {attendance_sessions} ats ON ats.id = al.sessionid JOIN {attendance} a ON a.id = ats.attendanceid
                WHERE a.course = :courseid"],
            [['assign_submission'], "SELECT s.userid, " . self::cm_of('assign', 'a.id') . " AS cmid FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment
                WHERE a.course = :courseid AND s.userid > 0 AND s.status <> 'new'"],
            // A submission of a group (team submission) is kept without a person (userid 0): progress of each member of the group,
            // and, when the group has no member any more or it is the default group (people in no group), progress all the same:
            // one per submission, with a number that is never a person (fail closed).
            [['assign_submission', 'groups_members'], "SELECT gm.userid, " . self::cm_of('assign', 'a.id') . " AS cmid
                FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment JOIN {groups_members} gm ON gm.groupid = s.groupid
                WHERE a.course = :courseid AND s.userid = 0 AND s.groupid > 0 AND s.status <> 'new'"],
            [['assign_submission', 'groups_members'], "SELECT -s.id AS userid, " . self::cm_of('assign', 'a.id') . " AS cmid
                FROM {assign_submission} s
                JOIN {assign} a ON a.id = s.assignment
                WHERE a.course = :courseid AND s.userid = 0 AND s.status <> 'new'
                  AND NOT EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.groupid = s.groupid AND s.groupid > 0)"],
            // A grade given in the assignment and not released to the gradebook yet (marking workflow) is not in grade_grades.
            [['assign_grades'], "SELECT ag.userid, " . self::cm_of('assign', 'a.id') . " AS cmid FROM {assign_grades} ag
                JOIN {assign} a ON a.id = ag.assignment
                WHERE a.course = :courseid AND ag.grade >= 0"],
            [['quiz_attempts'], "SELECT qa.userid, " . self::cm_of('quiz', 'q.id') . " AS cmid FROM {quiz_attempts} qa
                JOIN {quiz} q ON q.id = qa.quiz WHERE q.course = :courseid AND qa.preview = 0"],
            [['forum_posts'], "SELECT p.userid, " . self::cm_of('forum', 'd.forum') . " AS cmid FROM {forum_posts} p
                JOIN {forum_discussions} d ON d.id = p.discussion
                WHERE d.course = :courseid AND p.userid <> :miwlabuser"],
            [['feedback_completed'], "SELECT fc.userid, " . self::cm_of('feedback', 'f.id') . " AS cmid FROM {feedback_completed} fc
                JOIN {feedback} f ON f.id = fc.feedback WHERE f.course = :courseid"],
            [['choice_answers'], "SELECT ca.userid, " . self::cm_of('choice', 'c.id') . " AS cmid FROM {choice_answers} ca
                JOIN {choice} c ON c.id = ca.choiceid WHERE c.course = :courseid"],
            [['lesson_attempts'], "SELECT la.userid, " . self::cm_of('lesson', 'l.id') . " AS cmid FROM {lesson_attempts} la
                JOIN {lesson} l ON l.id = la.lessonid WHERE l.course = :courseid"],
            [['lesson_grades'], "SELECT lg.userid, " . self::cm_of('lesson', 'l.id') . " AS cmid FROM {lesson_grades} lg
                JOIN {lesson} l ON l.id = lg.lessonid WHERE l.course = :courseid"],
            [['h5pactivity_attempts'], "SELECT ha.userid, " . self::cm_of('h5pactivity', 'h.id') . " AS cmid
                FROM {h5pactivity_attempts} ha
                JOIN {h5pactivity} h ON h.id = ha.h5pactivityid WHERE h.course = :courseid"],
            [['scorm_attempt'], "SELECT sa.userid, " . self::cm_of('scorm', 's.id') . " AS cmid FROM {scorm_attempt} sa
                JOIN {scorm} s ON s.id = sa.scormid WHERE s.course = :courseid"],
            [['scorm_scoes_track'], "SELECT st.userid, " . self::cm_of('scorm', 's.id') . " AS cmid FROM {scorm_scoes_track} st
                JOIN {scorm} s ON s.id = st.scormid WHERE s.course = :courseid"],
            [['data_records'], "SELECT r.userid, " . self::cm_of('data', 'd.id') . " AS cmid FROM {data_records} r
                JOIN {data} d ON d.id = r.dataid WHERE d.course = :courseid"],
            [['glossary_entries'], "SELECT e.userid, " . self::cm_of('glossary', 'g.id') . " AS cmid FROM {glossary_entries} e
                JOIN {glossary} g ON g.id = e.glossaryid WHERE g.course = :courseid"],
            [['wiki_pages', 'wiki_subwikis'], "SELECT p.userid, " . self::cm_of('wiki', 'w.id') . " AS cmid FROM {wiki_pages} p
                JOIN {wiki_subwikis} sw ON sw.id = p.subwikiid
                JOIN {wiki} w ON w.id = sw.wikiid WHERE w.course = :courseid"],
            [['workshop_submissions'], "SELECT ws.authorid AS userid, " . self::cm_of('workshop', 'w.id') . " AS cmid
                FROM {workshop_submissions} ws
                JOIN {workshop} w ON w.id = ws.workshopid WHERE w.course = :courseid AND ws.example = 0"],
            [['survey_answers'], "SELECT sa.userid, " . self::cm_of('survey', 's.id') . " AS cmid FROM {survey_answers} sa
                JOIN {survey} s ON s.id = sa.survey WHERE s.course = :courseid"],
            [['chat_messages'], "SELECT m.userid, " . self::cm_of('chat', 'c.id') . " AS cmid FROM {chat_messages} m
                JOIN {chat} c ON c.id = m.chatid WHERE c.course = :courseid AND m.issystem = 0"],
            [['customcert_issues'], "SELECT ci.userid, " . self::cm_of('customcert', 'c.id') . " AS cmid FROM {customcert_issues} ci
                JOIN {customcert} c ON c.id = ci.customcertid WHERE c.course = :courseid"],
            [['questionnaire_response', 'questionnaire'], "SELECT r.userid, " . self::cm_of('questionnaire', 'q.id') . " AS cmid
                FROM {questionnaire_response} r
                JOIN {questionnaire} q ON q.id = r.questionnaireid WHERE q.course = :courseid"],
            [['post'], "SELECT userid, 0 AS cmid FROM {post} WHERE module = 'notes' AND courseid = :courseid"],
        ];
    }

    /**
     * The activity modules the progress queries cover: those whose data about a person they read, and those that keep no data
     * about a person (resources, labels, sections). A course with an activity of any other module is never replaced: the plugin
     * cannot tell whether somebody has progress in it (fail closed).
     */
    const KNOWN_MODULES = [
        'assign', 'quiz', 'forum', 'feedback', 'choice', 'lesson', 'h5pactivity', 'scorm', 'data', 'glossary', 'wiki', 'workshop',
        'survey', 'chat', 'customcert', 'questionnaire', 'lti', 'bigbluebuttonbn', 'hvp', 'attendance',
        'page', 'label', 'url', 'resource', 'folder', 'book', 'imscp', 'subsection', 'qbank', 'board', 'coursecertificate',
    ];

    /**
     * Only for PHPUnit (ignored otherwise): the modules taken as known instead of KNOWN_MODULES.
     *
     * @var string[]|null
     */
    public static $knownmoduleshook = null;

    /**
     * The modules of the activities of the course that the progress queries do not cover, by name.
     *
     * @param int $courseid
     * @return string[]
     */
    public static function unknown_modules(int $courseid): array {
        global $DB;
        $known = (defined('PHPUNIT_TEST') && PHPUNIT_TEST && self::$knownmoduleshook !== null) ? self::$knownmoduleshook
            : self::KNOWN_MODULES;
        $names = $DB->get_fieldset_sql(
            "SELECT DISTINCT m.name FROM {course_modules} cm JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0",
            ['courseid' => $courseid]
        );
        $unknown = array_values(array_diff($names, $known));
        sort($unknown);
        return $unknown;
    }

    /**
     * How many people are enrolled in the course (active or not; the MiwLab account never counts), and how many have
     * progress there, enrolled or not. Counts only: no person is named.
     *
     * @param int $courseid
     * @return array ['enrolled' => int, 'withprogress' => int]
     */
    public static function people(int $courseid): array {
        global $DB, $USER;
        $dbman = $DB->get_manager();
        $account = (int) get_config('local_miwlab', 'accountid');
        // The account of the MiwLab never counts as a person. The forum posts of the account that is running (the web service
        // and its background task run as it) do not count either, also when the key was made by hand and the plugin does not
        // know the account: they are the opening topics the plugin itself recreated (1.7.0).
        $excluded = $account > 0 ? [$account] : [];
        $enrolled = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT ue.userid)
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {user} u ON u.id = ue.userid AND u.deleted = 0
              WHERE e.courseid = :courseid AND ue.userid <> :account",
            ['courseid' => $courseid, 'account' => $account]
        );
        $parts = [];
        $params = [];
        foreach (self::progress_queries() as $i => [$tables, $sql]) {
            foreach ($tables as $table) {
                if (!$dbman->table_exists($table)) {
                    continue 2;
                }
            }
            // Each query has its own names for the course (a query may use it twice: :courseid and :courseid2).
            $parts[] = preg_replace_callback('/:courseid(\d*)/', function (array $m) use ($i, $courseid, &$params): string {
                $name = 'c' . $i . 'x' . $m[1];
                $params[$name] = $courseid;
                return ':' . $name;
            }, $sql);
        }
        $params['miwlabuser'] = (int) $USER->id;
        $withprogress = 0;
        if ($parts) {
            // A record kept without the person (userid 0, an anonymous answer) counts as one more person with progress; only the
            // MiwLab account, when there is one, is left out.
            $where = '';
            if ($excluded) {
                [$excludedsql, $excludedparams] = $DB->get_in_or_equal($excluded, SQL_PARAMS_NAMED, 'excl', false);
                $where = ' WHERE x.userid ' . $excludedsql;
                $params += $excludedparams;
            }
            $withprogress = (int) $DB->count_records_sql(
                "SELECT COUNT(DISTINCT x.userid) FROM (" . implode(' UNION ', $parts) . ") x" . $where,
                $params
            );
        }
        return ['enrolled' => $enrolled, 'withprogress' => $withprogress];
    }

    /**
     * Who has progress, activity by activity (1.9.0, issue 204): the same queries as people(), with the activity of each row. The
     * people are the same as the ones people() counts (the MiwLab account never counts); a row that belongs to the course as a
     * whole
     * (the course completion, the course total and manual items of the gradebook, the badges, the notes) is listed apart.
     * Counts only, by design of the callers: no person is ever named outside this class.
     *
     * @param int $courseid
     * @return array ['activities' => [cmid => int[] userids], 'course' => int[] userids]
     */
    public static function progress_by_activity(int $courseid): array {
        global $DB, $USER;
        $dbman = $DB->get_manager();
        $account = (int) get_config('local_miwlab', 'accountid');
        $activities = [];
        $course = [];
        foreach (self::progress_queries() as $i => [$tables, $sql]) {
            foreach ($tables as $table) {
                if (!$dbman->table_exists($table)) {
                    continue 2;
                }
            }
            $params = [];
            $query = preg_replace_callback('/:courseid(\d*)/', function (array $m) use ($i, $courseid, &$params): string {
                $name = 'c' . $i . 'x' . $m[1];
                $params[$name] = $courseid;
                return ':' . $name;
            }, $sql);
            if (strpos($query, ':miwlabuser') !== false) {
                $params['miwlabuser'] = (int) $USER->id;
            }
            $rows = $DB->get_recordset_sql($query, $params);
            foreach ($rows as $row) {
                $userid = (int) $row->userid;
                if ($account > 0 && $userid === $account) {
                    continue;
                }
                $cmid = (int) $row->cmid;
                if ($cmid > 0) {
                    $activities[$cmid][$userid] = true;
                } else {
                    $course[$userid] = true;
                }
            }
            $rows->close();
        }
        return [
            'activities' => array_map(fn(array $users): array => array_keys($users), $activities),
            'course' => array_keys($course),
        ];
    }

    /**
     * Refuses when somebody has progress in the course and there are people enrolled, or when it has an activity of a module whose
     * progress the plugin cannot read and there are people enrolled (1.7.1, 1.7.2).
     *
     * @param int $courseid
     * @throws moodle_exception
     */
    public static function require_no_progress(int $courseid): void {
        $people = self::people($courseid);
        $unknown = self::unknown_modules($courseid);
        if ($unknown && $people['enrolled'] > 0) {
            throw new moodle_exception('error:unknownmodules', 'local_miwlab', '', implode(', ', $unknown));
        }
        if ($people['withprogress'] > 0 && $people['enrolled'] > 0) {
            throw new moodle_exception('error:hasprogress', 'local_miwlab', '', (object) [
                'people' => $people['withprogress'],
                'enrolled' => $people['enrolled'],
            ]);
        }
    }

    /**
     * The state of the content of a course (1.5.0), to know whether it changed in Moodle since the MiwLab last knew it: a
     * signature of its structure — each activity (type, instance, section, visibility, access restriction, completion, ID
     * number, group mode), each section (number, name, summary, order of its activities, visibility, restriction) and the time
     * each activity was last edited — and the latest of the edit times (sections, activities, activities added). What people do
     * in the course (completion, grades, submissions, posts) is not part of it. A deleted activity changes the signature but not
     * the time.
     *
     * @param int $courseid
     * @return array ['signature' => string, 'contentmodified' => int, 'activities' => int, 'sections' => int]
     */
    public static function course_state(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/ddllib.php');
        $dbman = $DB->get_manager();
        $format = (string) $DB->get_field('course', 'format', ['id' => $courseid]);
        $cms = array_values($DB->get_records_sql(
            "SELECT cm.id, m.name AS modname, cm.instance, cm.section, cm.idnumber, cm.visible, cm.groupmode, cm.groupingid,
                    cm.indent, cm.completion, cm.completionview, cm.completionexpected, cm.availability, cm.added
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0
           ORDER BY cm.id",
            ['courseid' => $courseid]
        ));
        $sections = array_values($DB->get_records(
            'course_sections',
            ['course' => $courseid],
            'section',
            'id, section, name, summary, sequence, visible, availability, timemodified'
        ));
        $latest = 0;
        $instances = [];
        foreach (array_unique(array_column($cms, 'modname')) as $modname) {
            $table = new \xmldb_table($modname);
            if (!$dbman->table_exists($table) || !$dbman->field_exists($table, 'timemodified')) {
                continue;
            }
            foreach ($DB->get_records($modname, ['course' => $courseid], 'id', 'id, timemodified') as $row) {
                $instances[$modname . ':' . $row->id] = (int) $row->timemodified;
                $latest = max($latest, (int) $row->timemodified);
            }
        }
        ksort($instances);
        foreach ($cms as $cm) {
            $latest = max($latest, (int) $cm->added);
        }
        foreach ($sections as $section) {
            $latest = max($latest, (int) $section->timemodified);
        }
        $signature = sha1(json_encode([
            'format' => $format,
            'activities' => array_map(fn($cm) => array_map('strval', (array) $cm), $cms),
            'sections' => array_map(fn($s) => array_map('strval', (array) $s), $sections),
            'edited' => $instances,
        ]));
        return [
            'signature' => $signature,
            'contentmodified' => $latest,
            'activities' => count($cms),
            'sections' => count($sections),
        ];
    }

    /**
     * Refuses when the content of the course changed in Moodle since the MiwLab last knew it: a signature different from the
     * expected one or, without it, an edit after the time given. Nothing given: nothing to check.
     *
     * @param int $courseid
     * @param string $expectedsignature
     * @param int $unchangedsince
     * @throws moodle_exception error:coursechanged
     */
    public static function require_unchanged(int $courseid, string $expectedsignature, int $unchangedsince): void {
        if ($expectedsignature === '' && $unchangedsince <= 0) {
            return;
        }
        $state = self::course_state($courseid);
        $changed = $expectedsignature !== ''
            ? $state['signature'] !== $expectedsignature
            : $state['contentmodified'] > $unchangedsince;
        if ($changed) {
            throw new moodle_exception('error:coursechanged', 'local_miwlab');
        }
    }

    /**
     * Whether a replacement of the course is queued or running.
     *
     * @param int $courseid
     * @return bool
     */
    public static function in_progress(int $courseid): bool {
        global $DB;
        return $DB->record_exists_select(
            'local_miwlab_restores',
            'mode IN (:mode, :update) AND courseid = :courseid AND status IN (:queued, :running)',
            ['mode' => self::MODE, 'update' => updater::MODE, 'courseid' => $courseid, 'queued' => restorer::STATUS_QUEUED,
                'running' => restorer::STATUS_RUNNING]
        );
    }

    /**
     * Queues the replacement of the course content by the package; the background task does it.
     *
     * The caller has validated the context, the capabilities and that nobody has progress.
     *
     * @param stdClass $course
     * @param \stored_file $file
     * @param string $marker
     * @param string $expectedsignature The state the MiwLab knows (course_state()), checked again before replacing; '' none.
     * @param int $unchangedsince Without the signature: the course must not have been edited after this time; 0 none.
     * @param bool $forcediscard Whether to replace discarding existing progress.
     * @param int $keepdays Days the full backup made before a discard is kept (fullcopies::MIN_DAYS to MAX_DAYS).
     * @return stdClass The record of the restore.
     */
    public static function queue(
        stdClass $course,
        \stored_file $file,
        string $marker,
        string $expectedsignature = '',
        int $unchangedsince = 0,
        bool $forcediscard = false,
        int $keepdays = fullcopies::DEFAULT_DAYS
    ): stdClass {
        global $CFG, $DB, $USER;
        fullcopies::require_valid_days($keepdays);
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $record = (object) [
            'restoreid' => restore_controller::get_tempdir_name($course->id, $USER->id),
            'categoryid' => (int) $course->category,
            'courseid' => (int) $course->id,
            'mode' => self::MODE,
            'marker' => $marker === '' ? null : $marker,
            'expectedsignature' => $expectedsignature === '' ? null : $expectedsignature,
            'unchangedsince' => max(0, $unchangedsince),
            'forcediscard' => $forcediscard ? 1 : 0,
            'keepdays' => $keepdays,
            'status' => restorer::STATUS_QUEUED,
            'progress' => 0,
            'timecreated' => time(),
            'timestarted' => 0,
            'timefinished' => 0,
        ];
        $record->id = $DB->insert_record('local_miwlab_restores', $record);
        $task = new \local_miwlab\task\restore_course();
        $task->set_userid($USER->id);
        $task->set_custom_data((object) ['id' => (int) $record->id, 'fileid' => (int) $file->get_id()]);
        \core\task\manager::queue_adhoc_task($task);
        return $record;
    }

    /**
     * Runs a queued replacement, as the user who queued it. Never throws: a failure is recorded with the reason.
     *
     * @param stdClass $record The restore record, already running.
     * @param \stored_file|false $file The package in the draft area of the user (false: not found).
     * @param string $tempdir Where the package is extracted (the backup temp directory of the restore).
     */
    public static function run(stdClass $record, $file, string $tempdir): void {
        global $CFG, $DB, $USER;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $controller = null;
        $backup = null;
        $context = null;
        $course = null;
        $touched = false;
        $badgesremoved = [];
        $identity = null;
        $access = null;
        $backupname = '';
        $backupexpires = 0;
        $keptopics = '';
        try {
            $course = self::course((int) $record->courseid);
            $context = \context_course::instance($course->id);
            // The capabilities and the progress are checked again: they may have changed while the replacement waited.
            self::require_capabilities($context, $course);
            // 1.9.0 (issue 204): discarding progress is done with a full backup, with the people; no permission for it, no discard.
            $full = !empty($record->forcediscard);
            if ($full) {
                self::require_capabilities($context, $course, self::FULL_BACKUP_CAPABILITIES);
            }
            if (empty($record->forcediscard)) {
                self::require_no_progress($course->id);
            }
            self::require_unchanged($course->id, (string) ($record->expectedsignature ?? ''), (int) ($record->unchangedsince ?? 0));
            if (!$file || $file->is_directory()) {
                throw new moodle_exception('error:draftfilenotfound', 'local_miwlab');
            }
            $marker = restorer::extract($file, $tempdir, (string) $record->marker);
            $package = restorer::course_in_package($tempdir);
            // 1.7.0 (issue 178): Moodle deletes the extracted package when the restore ends, so the topics are kept aside first.
            $keepfailure = '';
            try {
                $keptopics = forum_topics::keep($tempdir);
            } catch (moodle_exception $e) {
                $keepfailure = $e->getMessage();
            }

            // 1. The backup of the course as it is: without it, nothing is replaced.
            // With a discard the copy is the full one (with the people), the only way back; otherwise it is made without people.
            $backup = self::backup($course->id, $full);
            $backupname = self::backup_name($course, $full);
            $backupexpires = $full ? time() + fullcopies::require_valid_days((int) ($record->keepdays ?? fullcopies::DEFAULT_DAYS))
                * DAYSECS : 0;
            $DB->set_field('local_miwlab_restores', 'backupfilename', $backupname, ['id' => $record->id]);
            // Somebody may have made progress while the backup ran.
            if (empty($record->forcediscard)) {
                self::require_no_progress($course->id);
            }

            // 2. What deleting the content would take from the course itself, kept aside.
            $identity = self::identity($course, $context, (int) $record->id);
            $access = self::access($course->id);

            // 3. The replacement. The precheck runs first on the course as it is, so that a package Moodle refuses changes nothing.
            $controller = self::controller($record->restoreid, $course, $record->id);
            self::precheck($controller, true);
            badges::require_none_awarded($course->id);
            $touched = true;
            // The badges of the old content are deleted, not archived as site badges (the backup has them).
            $badgesremoved = badges::remove_from_course($course->id);
            // 1.6.0 (issue 155): the content is deleted BEFORE the precheck that the restore uses. Moodle's precheck maps the
            // question categories and questions of the package to the ones of the course with the same stamp; when the package
            // came from this same course (its content imported into the MiwLab and sent back), they all match, and deleting the
            // content afterwards (as the restore does) leaves the restored quizzes and the course question bank pointing to
            // nothing. Deleted first, nothing matches and the restore creates them from the package.
            $controller->destroy();
            $controller = null;
            \restore_dbops::delete_course_content($course->id, ['keep_roles_and_enrolments' => 1, 'keep_groups_and_groupings' => 1,
                'userid' => (int) $USER->id]);
            $controller = self::controller($record->restoreid, $course, $record->id);
            self::precheck($controller, false);
            $controller->execute_plan();
            $controller->destroy();
            $controller = null;
            if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && self::$afterplanhook) {
                call_user_func(self::$afterplanhook, $course->id);
            }

            self::put_back($course, $context, $identity, $access, $package);
            badges::activate_restored($course->id, []);
            // 1.7.0 (issue 178): the opening topics of the forums that the package carries (the content was deleted with its
            // discussions, so nothing is there yet). A failure here never undoes the replacement: it is counted.
            $topics = $keepfailure !== ''
                ? ['created' => 0, 'skipped' => 1, 'existing' => 0, 'note' => $keepfailure]
                : forum_topics::create_from_package((int) $course->id, $keptopics);
            $marker = self::write_marker($course->id, $marker);
            self::keep_backup($context, $backupname, $backup, $backupexpires);
            $backup = null;
            self::restore_backups($context, $identity);
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'fullbackup' => $backupexpires > 0 ? 1 : 0,
                'backupexpires' => $backupexpires,
                'status' => restorer::STATUS_FINISHED,
                'progress' => 1,
                'timefinished' => time(),
                'topicscreated' => $topics['created'],
                'topicsskipped' => $topics['skipped'],
                'topicsnote' => $topics['note'] === '' ? null : $topics['note'],
            ]);
        } catch (\Throwable $e) {
            if ($controller) {
                $controller->destroy();
            }
            $code = $e instanceof moodle_exception ? substr($e->errorcode, 0, 100) : 'unexpected';
            $reason = restorer::reason($e);
            $rolledback = 0;
            if ($touched) {
                // The content was deleted (at least in part): the backup goes back into the course.
                try {
                    self::roll_back($course, $backup, $record->restoreid);
                    if ($identity) {
                        self::put_back($course, $context, $identity, $access, null);
                    }
                    badges::put_back_statuses($course->id, $badgesremoved);
                    $rolledback = 1;
                    $code = 'error:replacefailed';
                } catch (\Throwable $rollback) {
                    $code = 'error:rollbackfailed';
                    $reason .= ' / ' . restorer::reason($rollback);
                }
            }
            // The backup is kept in the course backup area only when the course was touched; otherwise nothing changed.
            try {
                if ($backup && $touched && $context) {
                    self::keep_backup($context, $backupname, $backup, $backupexpires);
                    $DB->update_record('local_miwlab_restores', (object) ['id' => $record->id,
                        'fullbackup' => $backupexpires > 0 ? 1 : 0, 'backupexpires' => $backupexpires]);
                } else if ($backup) {
                    $backup->delete();
                    $DB->set_field('local_miwlab_restores', 'backupfilename', null, ['id' => $record->id]);
                }
                if ($identity && $context) {
                    self::restore_backups($context, $identity);
                }
            } catch (\Throwable $ignored) {
                // The backup stays in the user backup area of the account; the failure is already recorded.
                $reason .= ' / ' . restorer::reason($ignored);
            }
            $DB->update_record('local_miwlab_restores', (object) [
                'id' => $record->id,
                'status' => restorer::STATUS_FAILED,
                'rolledback' => $rolledback,
                'errorcode' => $code,
                'errormessage' => \core_text::substr($reason, 0, 2000),
                'timefinished' => time(),
            ]);
        } finally {
            if ($identity && $context) {
                get_file_storage()->delete_area_files($context->id, 'local_miwlab', self::STASH_AREA, (int) $record->id);
            }
            if ($keptopics !== '') {
                fulldelete($keptopics);
            }
        }
    }

    /**
     * The backup of the course as the current user. Moodle stores it in the user backup area of whoever made it; keep_backup()
     * moves it to the course backup area once the replacement ends.
     *
     * Without people (the default) the settings that carry them are set off explicitly and checked after setting (1.9.0: the
     * role has moodle/backup:userinfo, so the permission no longer keeps them out). With people ($full, only for the replacement
     * that discards progress) they are set on, and checked.
     *
     * @param int $courseid
     * @param bool $full With the people.
     * @return \stored_file
     * @throws moodle_exception error:backupwithpeople, error:fullbackupnotallowed, error:fullbackupfailed or error:backupfailed
     */
    public static function backup(int $courseid, bool $full = false): \stored_file {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        try {
            if (defined('PHPUNIT_TEST') && PHPUNIT_TEST && self::$backuphook) {
                $file = call_user_func(self::$backuphook, $courseid);
            } else {
                $bc = new \backup_controller(
                    backup::TYPE_1COURSE,
                    $courseid,
                    backup::FORMAT_MOODLE,
                    backup::INTERACTIVE_NO,
                    backup::MODE_GENERAL,
                    (int) $USER->id
                );
                try {
                    if ($full) {
                        backup_scope::include_people($bc->get_plan(), \context_course::instance($courseid));
                        // Moodle stores a backup WITH people straight in the course backup area, where deleting the content
                        // takes it
                        // away: a name of its own keeps it from touching a backup a teacher left there, and it is moved
                        // aside below.
                        $bc->get_plan()->get_setting('filename')->set_value('miwlab-full-' . bin2hex(random_bytes(6)) . '.mbz');
                    } else {
                        backup_scope::exclude_people($bc->get_plan());
                    }
                    $bc->execute_plan();
                    $file = $bc->get_results()['backup_destination'] ?? null;
                } finally {
                    $bc->destroy();
                }
            }
            if (!$file instanceof \stored_file || $file->get_filesize() <= 0) {
                throw new moodle_exception('error:backupempty', 'local_miwlab');
            }
            return $full ? self::move_to_private_area($file) : $file;
        } catch (moodle_exception $e) {
            if (in_array($e->errorcode, ['error:backupwithpeople', 'error:fullbackupnotallowed'], true)) {
                throw $e;
            }
            $code = $full ? 'error:fullbackupfailed' : 'error:backupfailed';
            throw new moodle_exception($code, 'local_miwlab', '', restorer::reason($e));
        } catch (\Throwable $e) {
            $code = $full ? 'error:fullbackupfailed' : 'error:backupfailed';
            throw new moodle_exception($code, 'local_miwlab', '', restorer::reason($e));
        }
    }

    /**
     * Moves a backup that Moodle stored in the course backup area (the ones with people) to the private backup area of the account,
     * where the backup without people already is: deleting the content of the course would delete it from the course area.
     * keep_backup() puts it back, with the name the plugin gives, once the replacement ends.
     *
     * @param \stored_file $file
     * @return \stored_file The file in the private area of the account.
     */
    protected static function move_to_private_area(\stored_file $file): \stored_file {
        global $USER;
        if ($file->get_component() === 'user' && $file->get_filearea() === 'backup') {
            return $file;
        }
        $moved = get_file_storage()->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'backup',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'miwlab-full-' . bin2hex(random_bytes(6)) . '.mbz',
        ], $file);
        $file->delete();
        return $moved;
    }

    /**
     * Runs the precheck of the restore and refuses the package when Moodle finds errors.
     *
     * @param restore_controller $controller
     * @param bool $droptemptables Drop the temporary tables after (a later controller of the same restore runs its own precheck).
     */
    protected static function precheck(restore_controller $controller, bool $droptemptables): void {
        if (!$controller->execute_precheck($droptemptables)) {
            $results = $controller->get_precheck_results();
            if (!empty($results['errors'])) {
                throw new moodle_exception('error:precheck', 'local_miwlab', '', implode(' ', $results['errors']));
            }
        }
    }

    /**
     * The name the backup gets in the course backup area: "-nu" (no users) or, 1.9.0, "-full" (with the people).
     *
     * @param stdClass $course
     * @param bool $full
     * @return string
     */
    public static function backup_name(stdClass $course, bool $full = false): string {
        return 'miwlab-before-replace-course-' . $course->id . '-' . date('Ymd-Hi') . ($full ? '-full' : '-nu') . '.mbz';
    }

    /**
     * The restore of the package into the course, deleting its content first and keeping enrolments, roles, groups and the
     * course configuration (it is not overwritten: the course step does not run), with the dates moved to the start of the
     * course.
     *
     * @param string $restoreid
     * @param stdClass $course
     * @param int $recordid For the progress (0: none).
     * @return restore_controller
     */
    protected static function controller(string $restoreid, stdClass $course, int $recordid): restore_controller {
        global $USER;
        $controller = new restore_controller(
            $restoreid,
            $course->id,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            (int) $USER->id,
            backup::TARGET_EXISTING_DELETING
        );
        if ($recordid) {
            $controller->set_progress(new \core\progress\db_updater($recordid, 'local_miwlab_restores', 'progress'));
        }
        $plan = $controller->get_plan();
        $settings = [
            'keep_roles_and_enrolments' => 1,
            'keep_groups_and_groupings' => 1,
            'overwrite_conf' => 0,
        ];
        if ((int) $course->startdate > 0) {
            $settings['course_startdate'] = (int) $course->startdate;
        }
        foreach ($settings as $name => $value) {
            if ($plan->setting_exists($name) && $plan->get_setting($name)->get_status() == base_setting::NOT_LOCKED) {
                $plan->get_setting($name)->set_value($value);
            }
        }
        // Never delete the enrolments, roles or groups: refuse if the settings could not be kept.
        foreach (['keep_roles_and_enrolments', 'keep_groups_and_groupings'] as $name) {
            if (!$plan->setting_exists($name) || !$plan->get_setting($name)->get_value()) {
                $controller->destroy();
                throw new moodle_exception('error:cannotkeepenrolments', 'local_miwlab');
            }
        }
        return $controller;
    }

    /**
     * What deleting the content takes from the course itself and the replacement keeps: the summary and its files, the
     * course image, the tags and the backups of the course backup area. The files are copied aside, into a file area of
     * the plugin in the course context (Moodle does not empty it).
     *
     * @param stdClass $course
     * @param \context_course $context
     * @param int $itemid The restore record.
     * @return stdClass
     */
    protected static function identity(stdClass $course, \context_course $context, int $itemid): stdClass {
        $fs = get_file_storage();
        $areas = [['course', 'summary'], ['course', 'overviewfiles'], ['backup', 'course']];
        foreach ($areas as [$component, $filearea]) {
            foreach ($fs->get_area_files($context->id, $component, $filearea, false, 'id', false) as $f) {
                $fs->create_file_from_storedfile([
                    'component' => 'local_miwlab',
                    'filearea' => self::STASH_AREA,
                    'itemid' => $itemid,
                    'filepath' => '/' . $component . '/' . $filearea . '/' . (int) $f->get_itemid() . $f->get_filepath(),
                ], $f);
            }
        }
        return (object) [
            'itemid' => $itemid,
            'summary' => $course->summary,
            'summaryformat' => $course->summaryformat,
            'tags' => \core_tag_tag::get_item_tags_array('core', 'course', $course->id),
        ];
    }

    /**
     * The roles and enrolments the current user has in the course, to take back what the restore gives.
     *
     * @param int $courseid
     * @return array ['roles' => int[], 'enrolments' => int[]]
     */
    protected static function access(int $courseid): array {
        global $DB, $USER;
        $context = \context_course::instance($courseid);
        return [
            'roles' => array_map('intval', $DB->get_fieldset_select(
                'role_assignments',
                'id',
                'userid = ? AND contextid = ?',
                [$USER->id, $context->id]
            )),
            'enrolments' => array_map('intval', $DB->get_fieldset_sql(
                "SELECT ue.id FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid WHERE e.courseid = ? AND ue.userid = ?",
                [$courseid, $USER->id]
            )),
        ];
    }

    /**
     * After the restore: the summary, its files, the image and the tags back; the roles and enrolments the restore gave the
     * current user taken back; and the completion tracking on when the package uses it (the activities would not track it).
     *
     * @param stdClass $course The course as it was before.
     * @param \context_course $context
     * @param stdClass $identity What identity() kept.
     * @param array $access What access() found.
     * @param array|null $package What the package says of its course (course_in_package()); null: nothing to apply.
     */
    protected static function put_back(
        stdClass $course,
        \context_course $context,
        stdClass $identity,
        array $access,
        ?array $package
    ): void {
        global $CFG, $DB, $USER;
        $fs = get_file_storage();
        foreach (['summary', 'overviewfiles'] as $filearea) {
            $fs->delete_area_files($context->id, 'course', $filearea);
        }
        foreach ($fs->get_area_files($context->id, 'local_miwlab', self::STASH_AREA, $identity->itemid, 'id', false) as $f) {
            if (!preg_match('~^/course/(summary|overviewfiles)/(\d+)(/.*)$~', $f->get_filepath(), $m)) {
                continue;
            }
            $fs->create_file_from_storedfile([
                'component' => 'course',
                'filearea' => $m[1],
                'itemid' => (int) $m[2],
                'filepath' => $m[3],
            ], $f);
        }
        $update = ['id' => $course->id, 'summary' => $identity->summary, 'summaryformat' => $identity->summaryformat];
        if ($package && !empty($package['enablecompletion']) && !empty($CFG->enablecompletion)) {
            $update['enablecompletion'] = 1;
        }
        $DB->update_record('course', (object) $update);
        \core_tag_tag::set_item_tags('core', 'course', $course->id, $context, $identity->tags);

        $context = \context_course::instance($course->id);
        foreach ($DB->get_records('role_assignments', ['userid' => $USER->id, 'contextid' => $context->id]) as $ra) {
            if (!in_array((int) $ra->id, $access['roles'], true)) {
                role_unassign($ra->roleid, $USER->id, $context->id, $ra->component, $ra->itemid);
            }
        }
        $instances = $DB->get_records_sql(
            "SELECT e.*, ue.id AS ueid FROM {enrol} e JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE e.courseid = :courseid AND ue.userid = :userid",
            ['courseid' => $course->id, 'userid' => $USER->id]
        );
        foreach ($instances as $instance) {
            if (!in_array((int) $instance->ueid, $access['enrolments'], true)) {
                $plugin = enrol_get_plugin($instance->enrol);
                if ($plugin) {
                    $plugin->unenrol_user($instance, $USER->id);
                }
            }
        }
        \cache_helper::purge_by_event('changesincourse');
        rebuild_course_cache($course->id, true);
    }

    /**
     * The backups that were in the course backup area, put back (Moodle empties the area when it deletes the content).
     *
     * @param \context_course $context
     * @param stdClass $identity
     */
    protected static function restore_backups(\context_course $context, stdClass $identity): void {
        $fs = get_file_storage();
        foreach ($fs->get_area_files($context->id, 'local_miwlab', self::STASH_AREA, $identity->itemid, 'id', false) as $f) {
            if (!preg_match('~^/backup/course/(\d+)(/.*)$~', $f->get_filepath(), $m)) {
                continue;
            }
            if (!$fs->file_exists($context->id, 'backup', 'course', (int) $m[1], $m[2], $f->get_filename())) {
                $fs->create_file_from_storedfile([
                    'component' => 'backup',
                    'filearea' => 'course',
                    'itemid' => (int) $m[1],
                    'filepath' => $m[2],
                ], $f);
            }
        }
    }

    /**
     * The backup made before the replacement, moved from the user backup area of the account to the course backup area
     * (Course > Restore > Course backup area), with the backups that were there before.
     *
     * @param \context_course $context
     * @param string $name The name it gets there (backup_name()).
     * @param \stored_file $backup
     * @param int $expires For a full backup (1.9.0): when it is deleted (Unix time), and it is registered; 0: a copy without
     * people.
     */
    public static function keep_backup(\context_course $context, string $name, \stored_file $backup, int $expires = 0): void {
        $fs = get_file_storage();
        $existing = $fs->get_file($context->id, 'backup', 'course', 0, '/', $name);
        if ($existing) {
            $existing->delete();
        }
        $kept = $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'backup',
            'filearea' => 'course',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $name,
        ], $backup);
        if ($expires > 0) {
            fullcopies::register((int) $context->instanceid, $kept, $expires);
        }
        $backup->delete();
    }

    /**
     * After a failed restore that had deleted content: the backup restored into the course, deleting what the failed restore
     * left, keeping enrolments, roles and groups.
     *
     * @param stdClass|null $course
     * @param \stored_file|null $backup
     * @param string $restoreid
     * @throws moodle_exception
     */
    protected static function roll_back(?stdClass $course, ?\stored_file $backup, string $restoreid): void {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        if (!$course || !$backup) {
            throw new moodle_exception('error:backupfailed', 'local_miwlab', '', '');
        }
        $rollbackid = substr(md5($restoreid . 'rollback'), 0, 32);
        $tempdir = make_backup_temp_directory($rollbackid);
        try {
            $packer = get_file_packer('application/vnd.moodle.backup');
            if (!$backup->extract_to_pathname($packer, $tempdir)) {
                throw new moodle_exception('error:notabackup', 'local_miwlab');
            }
            $controller = self::controller($rollbackid, $course, 0);
            try {
                self::precheck($controller, true);
                // What the failed restore brought, badges included, goes before the backup comes back.
                badges::remove_from_course($course->id);
                // Deleted before the precheck the restore uses (see run()): the question bank of the backup is created again.
                $controller->destroy();
                $controller = null;
                \restore_dbops::delete_course_content($course->id, ['keep_roles_and_enrolments' => 1,
                    'keep_groups_and_groupings' => 1, 'userid' => (int) $USER->id]);
                $controller = self::controller($rollbackid, $course, 0);
                self::precheck($controller, false);
                $controller->execute_plan();
            } finally {
                if ($controller) {
                    $controller->destroy();
                }
            }
        } finally {
            fulldelete($tempdir);
        }
    }

    /**
     * Writes the MiwLab marker in the course ID number when the course has none or already has a MiwLab marker: an ID number
     * the organisation gave (a code of another system) is kept. The ID number is unique: when another course has the marker,
     * the course id follows it, as in a new restore.
     *
     * @param int $courseid
     * @param string $marker
     * @return string The ID number of the course now.
     */
    protected static function write_marker(int $courseid, string $marker): string {
        global $DB;
        $current = (string) $DB->get_field('course', 'idnumber', ['id' => $courseid]);
        if ($marker === '' || ($current !== '' && !restorer::valid_marker($current))) {
            return $current;
        }
        $taken = $DB->record_exists_select('course', 'idnumber = :marker AND id <> :id', ['marker' => $marker, 'id' => $courseid]);
        if ($taken) {
            $marker .= ':' . $courseid;
        }
        $DB->set_field('course', 'idnumber', $marker, ['id' => $courseid]);
        \cache_helper::purge_by_event('changesincourse');
        rebuild_course_cache($courseid, true);
        return $marker;
    }

    /**
     * Hides the course (as Moodle's "Hide" in the course list does). Whether it was visible before.
     *
     * @param stdClass $course
     * @return bool
     */
    public static function hide(stdClass $course): bool {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $wasvisible = (bool) $course->visible;
        if ($wasvisible) {
            course_change_visibility($course->id, false);
        }
        return $wasvisible;
    }
}
