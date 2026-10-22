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
 * The opening topics of the forums (1.7.0, issue 178 of the MiwLab).
 *
 * A restore without people (the MiwLab package, or a content backup) brings no discussion back, except the one of a
 * "single simple discussion" forum, which Moodle recreates from the introduction. This class does two things:
 *
 * - reads, from a course, the opening topic of each discussion started by the staff of the course (a teacher with or without
 *   editing rights, a manager, a site administrator): the title, the text and the files. A topic started by anyone else
 *   (a student, a guest) is never read, and neither are replies, grades, ratings or subscriptions;
 * - recreates, after a restore, the topics that the package carries (miwlab/foruns.json and the files under miwlab/foruns/),
 *   as the account of the integration, without sending any notification, and without duplicating a topic that is already
 *   there (same forum, same title).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class forum_topics {
    /** @var int Version of the format of miwlab/foruns.json. */
    const FORMAT = 1;

    /** @var string The topics of a package, inside it. */
    const PACKAGE_JSON = 'miwlab/foruns.json';

    /** @var string The folder of the files of the topics inside a package (one file per content, named by its SHA-1). */
    const PACKAGE_FILES = 'miwlab/foruns/';

    /** @var string[] The roles whose topics are read, besides the site administrators. */
    const STAFF_ROLES = ['editingteacher', 'teacher', 'manager'];

    /** @var int At most this many forums with topics are read from a course. */
    const MAX_FORUMS = 300;

    /** @var int At most this many topics are read from a forum. */
    const MAX_TOPICS_PER_FORUM = 50;

    /** @var int At most this many topics are read from a course. */
    const MAX_TOPICS = 500;

    /** @var int The longest text of a topic (characters); a longer one is left out. */
    const MAX_MESSAGE = 200000;

    /** @var int At most this many files are read from a topic. */
    const MAX_FILES_PER_TOPIC = 10;

    /** @var int The largest file read (bytes); a larger one is left out. */
    const MAX_FILE_BYTES = 1048576;

    /** @var int All the files read from a course, together (bytes). */
    const MAX_TOTAL_BYTES = 3145728;

    /** @var int The whole answer (texts and files in base64) stays under this size, which the MiwLab accepts (8 MiB). */
    const MAX_RESPONSE_BYTES = 6291456;

    /** @var int The largest miwlab/foruns.json accepted. */
    const MAX_JSON_BYTES = 33554432;

    /** @var string Forum types whose topics Moodle recreates by itself on restore. */
    const TYPE_SINGLE = 'single';

    /** @var string The announcements forum. */
    const TYPE_NEWS = 'news';

    /**
     * The users with a staff role in the course (or in a category or the site, above it) among the given users.
     *
     * @param \context_course $context
     * @param int[] $userids
     * @return string[] The role of each user ('editingteacher', 'teacher', 'manager' or 'admin'), by user id.
     */
    protected static function staff_roles(\context_course $context, array $userids): array {
        global $DB;
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (!$userids) {
            return [];
        }
        [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
        [$csql, $cparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        [$rsql, $rparams] = $DB->get_in_or_equal(self::STAFF_ROLES, SQL_PARAMS_NAMED, 'rol');
        $sql = "SELECT ra.userid, r.shortname
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid $usql AND ra.contextid $csql AND r.shortname $rsql";
        $found = [];
        $rank = array_flip(self::STAFF_ROLES);
        foreach ($DB->get_recordset_sql($sql, $uparams + $cparams + $rparams) as $row) {
            $current = $found[(int) $row->userid] ?? null;
            if ($current === null || $rank[$row->shortname] < $rank[$current]) {
                $found[(int) $row->userid] = $row->shortname;
            }
        }
        foreach ($userids as $userid) {
            if (!isset($found[$userid]) && is_siteadmin($userid)) {
                $found[$userid] = 'admin';
            }
        }
        return $found;
    }

    /**
     * The text of a post as HTML, ready for the MiwLab (the files of the post stay as @@PLUGINFILE@@ addresses).
     *
     * @param string $message
     * @param int $format
     * @param \context $context
     * @return string
     */
    protected static function html(string $message, int $format, \context $context): string {
        if ((int) $format === FORMAT_HTML) {
            return clean_text($message, FORMAT_HTML);
        }
        return format_text($message, $format, ['context' => $context, 'para' => false, 'filter' => false, 'noclean' => false]);
    }

    /**
     * Reads the opening topics started by the staff of the course, forum by forum.
     *
     * Forums of the "single simple discussion" type are left out (Moodle recreates their discussion). A discussion limited to a
     * group, one with a display period, a deleted post and a private reply are not read either.
     *
     * @param stdClass $course
     * @return array ['forums' => [...], 'omittedtopics' => int]
     */
    public static function read(stdClass $course): array {
        global $DB;
        global $CFG;
        $context = \context_course::instance($course->id);
        // The staff is decided by the database: only the first posts of staff members are read, one at a time.
        [$csql, $cparams] = $DB->get_in_or_equal($context->get_parent_context_ids(true), SQL_PARAMS_NAMED, 'ctx');
        [$rsql, $rparams] = $DB->get_in_or_equal(self::STAFF_ROLES, SQL_PARAMS_NAMED, 'rol');
        $admins = array_values(array_filter(array_map('intval', explode(',', (string) ($CFG->siteadmins ?? '')))));
        if ($admins) {
            [$asql, $aparams] = $DB->get_in_or_equal($admins, SQL_PARAMS_NAMED, 'adm');
            $isadmin = "p.userid $asql";
        } else {
            [$isadmin, $aparams] = ['1 = 0', []];
        }
        $sql = "SELECT d.id AS discussionid, d.forum AS forumid, d.name AS subject, d.pinned, d.groupid, d.timestart, d.timeend,
                       p.id AS postid, p.userid, p.message, p.messageformat, p.created,
                       cm.id AS cmid, f.type, f.name AS forumname
                  FROM {forum_discussions} d
                  JOIN {forum_posts} p ON p.id = d.firstpost
                  JOIN {forum} f ON f.id = d.forum
                  JOIN {modules} m ON m.name = :modname
                  JOIN {course_modules} cm ON cm.module = m.id AND cm.instance = f.id AND cm.course = d.course
                  JOIN {user} u ON u.id = p.userid AND u.deleted = 0
                 WHERE d.course = :courseid AND cm.deletioninprogress = 0 AND p.deleted = 0 AND p.parent = 0
                       AND p.privatereplyto = 0 AND f.type <> :single
                       AND d.groupid <= 0 AND d.timestart = 0 AND d.timeend = 0
                       AND (EXISTS (SELECT 1 FROM {role_assignments} ra JOIN {role} r ON r.id = ra.roleid
                                     WHERE ra.userid = p.userid AND ra.contextid $csql AND r.shortname $rsql)
                            OR $isadmin)
              ORDER BY cm.id, p.created, d.id";
        $params = ['modname' => 'forum', 'courseid' => $course->id, 'single' => self::TYPE_SINGLE]
            + $cparams + $rparams + $aparams;
        $rows = $DB->get_recordset_sql($sql, $params);
        $roles = [];
        $fs = get_file_storage();
        $forums = [];
        $omitted = 0;
        $topics = 0;
        $bytes = 0;
        $response = 0;
        foreach ($rows as $row) {
            if (!isset($roles[(int) $row->userid])) {
                $roles += self::staff_roles($context, [(int) $row->userid]);
                if (!isset($roles[(int) $row->userid])) {
                    continue;
                }
            }
            $cmid = (int) $row->cmid;
            if (!isset($forums[$cmid])) {
                if (count($forums) >= self::MAX_FORUMS) {
                    $omitted++;
                    continue;
                }
                $forums[$cmid] = ['cmid' => $cmid, 'type' => (string) $row->type, 'name' => (string) $row->forumname,
                    'topics' => [], 'omittedtopics' => 0];
            }
            if ($topics >= self::MAX_TOPICS || count($forums[$cmid]['topics']) >= self::MAX_TOPICS_PER_FORUM) {
                $forums[$cmid]['omittedtopics']++;
                $omitted++;
                continue;
            }
            $modcontext = \context_module::instance($cmid);
            $message = self::html((string) $row->message, (int) $row->messageformat, $modcontext);
            if (\core_text::strlen($message) > self::MAX_MESSAGE || $response + strlen($message) > self::MAX_RESPONSE_BYTES) {
                $forums[$cmid]['omittedtopics']++;
                $omitted++;
                continue;
            }
            $response += strlen($message) + 1024;
            $files = [];
            $omittedfiles = 0;
            foreach (['attachment' => false, 'post' => true] as $area => $embedded) {
                $stored = $fs->get_area_files($modcontext->id, 'mod_forum', $area, (int) $row->postid, 'filename', false);
                foreach ($stored as $file) {
                    $size = (int) $file->get_filesize();
                    if (
                        clean_param($file->get_filename(), PARAM_FILE) !== $file->get_filename()
                        || count($files) >= self::MAX_FILES_PER_TOPIC || $size > self::MAX_FILE_BYTES
                        || $bytes + $size > self::MAX_TOTAL_BYTES || $file->get_filepath() !== '/'
                        || $response + 4 * intdiv($size + 2, 3) > self::MAX_RESPONSE_BYTES
                    ) {
                        $omittedfiles++;
                        continue;
                    }
                    $bytes += $size;
                    $response += 4 * intdiv($size + 2, 3) + 256;
                    $files[] = [
                        'filename' => $file->get_filename(),
                        'mimetype' => $file->get_mimetype(),
                        'filesize' => $size,
                        'embedded' => $embedded,
                        'content' => base64_encode($file->get_content()),
                    ];
                }
            }
            $topics++;
            $forums[$cmid]['topics'][] = [
                'discussionid' => (int) $row->discussionid,
                'subject' => (string) $row->subject,
                'message' => $message,
                'pinned' => (int) $row->pinned === 1,
                'created' => (int) $row->created,
                'authorrole' => $roles[(int) $row->userid],
                'files' => $files,
                'omittedfiles' => $omittedfiles,
            ];
        }
        $rows->close();
        return ['forums' => array_values($forums), 'omittedtopics' => $omitted];
    }

    /**
     * Takes the topics out of the extracted package, to a folder of their own: Moodle deletes the extracted package when the
     * restore ends, and the topics are created after it.
     *
     * @param string $dir The extracted package.
     * @return string The folder to give to create_from_package(); empty when the package carries no topics. The caller deletes it.
     * @throws \moodle_exception error:forumtopicskeep when the topics cannot be moved aside (none will be created).
     */
    public static function keep(string $dir): string {
        if (!is_file($dir . '/' . self::PACKAGE_JSON)) {
            return '';
        }
        $kept = make_temp_directory('local_miwlab_topics/' . random_string(16));
        if (!@rename($dir . '/miwlab', $kept . '/miwlab')) {
            fulldelete($kept);
            throw new \moodle_exception('error:forumtopicskeep', 'local_miwlab');
        }
        return $kept;
    }

    /**
     * Reads and checks the topics a package carries.
     *
     * @param string $dir The extracted package.
     * @return array|null Null when the package carries none; otherwise the forums, each with its topics.
     * @throws \moodle_exception error:forumtopicsinvalid when the file is not what the MiwLab writes.
     */
    public static function read_package(string $dir): ?array {
        $path = $dir . '/' . self::PACKAGE_JSON;
        if (!is_file($path)) {
            return null;
        }
        if (filesize($path) > self::MAX_JSON_BYTES) {
            throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['formato'] ?? null) !== self::FORMAT || !is_array($data['foruns'] ?? null)) {
            throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
        }
        $forums = [];
        $total = 0;
        foreach (array_slice($data['foruns'], 0, self::MAX_FORUMS) as $forum) {
            if (
                !is_array($forum) || !is_int($forum['secao'] ?? null) || !is_int($forum['posicao'] ?? null)
                || !is_string($forum['nome'] ?? null) || !is_array($forum['topicos'] ?? null)
            ) {
                throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
            }
            $topics = [];
            foreach (array_slice($forum['topicos'], 0, self::MAX_TOPICS_PER_FORUM) as $topic) {
                if (++$total > self::MAX_TOPICS) {
                    throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
                }
                $title = is_array($topic) && is_string($topic['titulo'] ?? null) ? trim($topic['titulo']) : '';
                $text = is_array($topic) && is_string($topic['texto'] ?? null) ? $topic['texto'] : null;
                if ($title === '' || $text === null || \core_text::strlen($text) > self::MAX_MESSAGE) {
                    throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
                }
                $files = [];
                $attachments = is_array($topic['anexos'] ?? null) ? $topic['anexos'] : [];
                foreach (array_slice($attachments, 0, self::MAX_FILES_PER_TOPIC) as $f) {
                    if (
                        !is_array($f) || !is_string($f['nome'] ?? null) || !is_string($f['sha1'] ?? null)
                        || !preg_match('/^[0-9a-f]{40}$/', $f['sha1'])
                    ) {
                        throw new \moodle_exception('error:forumtopicsinvalid', 'local_miwlab');
                    }
                    $files[] = ['name' => $f['nome'], 'sha1' => $f['sha1'], 'embedded' => ($f['embutido'] ?? false) === true];
                }
                $topics[] = [
                    'title' => \core_text::substr($title, 0, 255),
                    'message' => $text,
                    'pinned' => ($topic['fixado'] ?? false) === true,
                    'files' => $files,
                ];
            }
            $forums[] = [
                'section' => $forum['secao'],
                'position' => $forum['posicao'],
                'name' => $forum['nome'],
                'topics' => $topics,
            ];
        }
        return $forums;
    }

    /**
     * The forums of a restored course, by section number, in the order of the section (the same order the package was written in).
     *
     * @param int $courseid
     * @return array[] By section number, a list of objects (cmid, forumid, name, type).
     */
    protected static function forums_by_section(int $courseid): array {
        global $DB;
        $sql = "SELECT cm.id AS cmid, f.id AS forumid, f.name, f.type, cs.section AS number, cs.sequence
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {forum} f ON f.id = cm.instance
                  JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cm.course = :courseid AND cm.deletioninprogress = 0";
        $bysection = [];
        foreach ($DB->get_records_sql($sql, ['modname' => 'forum', 'courseid' => $courseid]) as $row) {
            $bysection[(int) $row->number]['sequence'] = (string) $row->sequence;
            $bysection[(int) $row->number]['forums'][(int) $row->cmid] = $row;
        }
        $result = [];
        foreach ($bysection as $number => $section) {
            $ordered = [];
            foreach (array_filter(array_map('intval', explode(',', $section['sequence']))) as $cmid) {
                if (isset($section['forums'][$cmid])) {
                    $ordered[] = $section['forums'][$cmid];
                }
            }
            $result[$number] = $ordered;
        }
        return $result;
    }

    /**
     * Whether the current user may start a discussion in the forum (the news forum asks for more).
     *
     * @param stdClass $forum
     * @param \context_module $context
     * @return bool
     */
    protected static function may_start(stdClass $forum, \context_module $context): bool {
        $capability = $forum->type === self::TYPE_NEWS ? 'mod/forum:addnews' : 'mod/forum:startdiscussion';
        return has_capability($capability, $context);
    }

    /**
     * Recreates, in the restored course, the topics that the package carries, as the current user (the account of the
     * integration). Never throws: what could not be created is counted, with the first reason.
     *
     * Nothing is sent: the posts are born as already mailed, and there is no subscription. A topic whose title already exists
     * in the forum is left alone (the same package applied twice makes no duplicate).
     *
     * @param int $courseid
     * @param string $dir The extracted package.
     * @return array ['created' => int, 'skipped' => int, 'existing' => int, 'note' => string]
     */
    public static function create_from_package(int $courseid, string $dir): array {
        $result = ['created' => 0, 'skipped' => 0, 'existing' => 0, 'note' => ''];
        try {
            $forums = self::read_package($dir);
        } catch (\Throwable $e) {
            return ['skipped' => 1, 'note' => get_string('error:forumtopicsinvalid', 'local_miwlab')] + $result;
        }
        if (!$forums) {
            return $result;
        }
        $bysection = self::forums_by_section($courseid);
        foreach ($forums as $forum) {
            $target = $bysection[$forum['section']][$forum['position']] ?? null;
            if (!$target || $target->name !== $forum['name']) {
                self::skip($result, count($forum['topics']), get_string('error:forumnotfound', 'local_miwlab', $forum['name']));
                continue;
            }
            if ($target->type === self::TYPE_SINGLE) {
                continue;
            }
            foreach ($forum['topics'] as $topic) {
                try {
                    $state = self::create_topic($courseid, $target, $topic, $dir);
                } catch (\Throwable $e) {
                    self::skip($result, 1, \core_text::substr($e->getMessage(), 0, 200));
                    continue;
                }
                if ($state === 'created') {
                    $result['created']++;
                } else if ($state === 'existing') {
                    $result['existing']++;
                } else {
                    self::skip($result, 1, $state);
                }
            }
        }
        return $result;
    }

    /**
     * Counts topics as not created and keeps the first reason.
     *
     * @param array $result
     * @param int $count
     * @param string $reason
     */
    protected static function skip(array &$result, int $count, string $reason): void {
        $result['skipped'] += $count;
        if ($result['note'] === '') {
            $result['note'] = \core_text::substr($reason, 0, 255);
        }
    }

    /**
     * Creates one topic.
     *
     * @param int $courseid
     * @param stdClass $target The forum (cmid, forumid, name, type).
     * @param array $topic
     * @param string $dir The extracted package.
     * @return string 'created', 'existing' (already there) or the reason it was not created.
     */
    protected static function create_topic(int $courseid, stdClass $target, array $topic, string $dir): string {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $context = \context_module::instance((int) $target->cmid);
        $forum = $DB->get_record('forum', ['id' => $target->forumid], '*', MUST_EXIST);
        $capability = $forum->type === self::TYPE_NEWS ? 'mod/forum:addnews' : 'mod/forum:startdiscussion';
        if (!self::may_start($forum, $context)) {
            return get_string('error:forumcapability', 'local_miwlab', $capability);
        }
        if ($DB->record_exists('forum_discussions', ['forum' => $forum->id, 'name' => $topic['title']])) {
            return 'existing';
        }
        $discussion = (object) [
            'course' => $courseid,
            'forum' => (int) $forum->id,
            'name' => $topic['title'],
            'message' => clean_text($topic['message'], FORMAT_HTML),
            'messageformat' => FORMAT_HTML,
            'messagetrust' => 0,
            'attachments' => 0,
            'mailnow' => 0,
            'groupid' => -1,
            'timestart' => 0,
            'timeend' => 0,
            'pinned' => $topic['pinned'] && has_capability('mod/forum:pindiscussions', $context)
                ? FORUM_DISCUSSION_PINNED : FORUM_DISCUSSION_UNPINNED,
        ];
        // The discussion, its mailed mark and its files go together: a failure in the middle leaves no half-made topic.
        $transaction = $DB->start_delegated_transaction();
        try {
            $discussionid = forum_add_discussion($discussion, null, null, (int) $USER->id);
            if (!$discussionid) {
                $transaction->allow_commit();
                return get_string('error:forumdiscussion', 'local_miwlab', $topic['title']);
            }
            $postid = (int) $DB->get_field('forum_discussions', 'firstpost', ['id' => $discussionid], MUST_EXIST);
            // Born as already mailed: the cron of the forum sends nothing about it, to nobody.
            $DB->set_field('forum_posts', 'mailed', FORUM_MAILED_SUCCESS, ['id' => $postid]);
            self::attach($forum, $context, $postid, $topic['files'], $dir);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
        return 'created';
    }

    /**
     * Saves the files of a topic, as long as they are what the package says they are and the forum accepts them.
     *
     * @param stdClass $forum
     * @param \context_module $context
     * @param int $postid
     * @param array $files
     * @param string $dir The extracted package.
     */
    protected static function attach(stdClass $forum, \context_module $context, int $postid, array $files, string $dir): void {
        global $DB, $USER;
        if (!$files || !has_capability('mod/forum:createattachment', $context)) {
            return;
        }
        $fs = get_file_storage();
        $maxbytes = (int) get_user_max_upload_file_size($context, 0, (int) $forum->maxbytes);
        $attached = 0;
        foreach ($files as $file) {
            $path = $dir . '/' . self::PACKAGE_FILES . $file['sha1'];
            $name = clean_param($file['name'], PARAM_FILE);
            if ($name === '' || !is_file($path) || sha1_file($path) !== $file['sha1']) {
                continue;
            }
            // A negative limit is "no limit" (the account can ignore file size limits).
            $toolarge = filesize($path) > self::MAX_FILE_BYTES || ($maxbytes > 0 && filesize($path) > $maxbytes);
            if ($toolarge || (!$file['embedded'] && $attached >= (int) $forum->maxattachments)) {
                continue;
            }
            $fs->create_file_from_pathname([
                'contextid' => $context->id,
                'component' => 'mod_forum',
                'filearea' => $file['embedded'] ? 'post' : 'attachment',
                'itemid' => $postid,
                'filepath' => '/',
                'filename' => $name,
                'userid' => (int) $USER->id,
            ], $path);
            $attached += $file['embedded'] ? 0 : 1;
        }
        if ($attached > 0) {
            $DB->set_field('forum_posts', 'attachment', 1, ['id' => $postid]);
        }
    }
}
