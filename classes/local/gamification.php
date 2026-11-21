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
 * The light gamification of a course (course badges and the progress bar) at any time, and the site switches it depends on
 * (1.12.0, issue 333).
 *
 * Until 1.11.0 the badges only came inside a package that the plugin restored (see badges.php), so a course that already
 * existed in Moodle never got them. Here they are added to an existing course, once, by name: a badge the course already has
 * (awarded or not) is never changed, deleted or created again.
 *
 * The site switches the plugin can turn on are a short fixed list and are only ever turned ON (never off): the competencies
 * of Moodle, the badges of the site, the badges of the courses, the completion tracking of the site, and the progress bar block
 * when it is installed but disabled. A block of another developer that is not installed is never installed by the plugin.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gamification {
    /** @var string The progress bar block (block_completion_progress, of another developer). */
    const PROGRESS_BLOCK = 'completion_progress';

    /** @var string[] The switches the plugin can turn on. */
    const FEATURES = ['competencies', 'badges', 'coursebadges', 'completion', 'progressblock'];

    /** @var int The biggest badge image the plugin takes (2 MB, as the MiwLab sends). */
    const MAX_IMAGE_BYTES = 2097152;

    /**
     * What the site has on and what the gamification depends on.
     *
     * @return array
     */
    public static function site_state(): array {
        global $CFG, $DB;
        $info = \core_plugin_manager::instance()->get_plugin_info('block_' . self::PROGRESS_BLOCK);
        $installed = $info && !empty($info->versiondb);
        $visible = $installed && (int) $DB->get_field('block', 'visible', ['name' => self::PROGRESS_BLOCK]) === 1;
        return [
            'competencies' => (bool) get_config('core_competency', 'enabled'),
            'badges' => !empty($CFG->enablebadges),
            'coursebadges' => !empty($CFG->badges_allowcoursebadges),
            'completion' => !empty($CFG->enablecompletion),
            'progressblockinstalled' => (bool) $installed,
            'progressblockenabled' => (bool) $visible,
        ];
    }

    /**
     * Turns on the given switches. Only ever on, only the ones of the list; each result says what happened.
     *
     * @param string[] $features
     * @return array[] [['feature' => string, 'result' => 'enabled'|'already'|'notinstalled'|'unknown']]
     */
    public static function enable(array $features): array {
        $results = [];
        foreach (array_values(array_unique($features)) as $feature) {
            $feature = (string) $feature;
            if (!in_array($feature, self::FEATURES, true)) {
                $results[] = ['feature' => $feature, 'result' => 'unknown'];
                continue;
            }
            $state = self::site_state();
            $already = $feature === 'progressblock' ? $state['progressblockenabled'] : $state[$feature];
            if ($already) {
                $results[] = ['feature' => $feature, 'result' => 'already'];
                continue;
            }
            switch ($feature) {
                case 'competencies':
                    set_config('enabled', 1, 'core_competency');
                    break;
                case 'badges':
                    set_config('enablebadges', 1);
                    break;
                case 'coursebadges':
                    set_config('badges_allowcoursebadges', 1);
                    break;
                case 'completion':
                    set_config('enablecompletion', 1);
                    break;
                case 'progressblock':
                    if (!$state['progressblockinstalled']) {
                        $results[] = ['feature' => $feature, 'result' => 'notinstalled'];
                        continue 2;
                    }
                    \core\plugininfo\block::enable_plugin(self::PROGRESS_BLOCK, 1);
                    break;
            }
            $results[] = ['feature' => $feature, 'result' => 'enabled'];
        }
        // Settings of the site are cached; a switch turned on must be seen by the next request.
        \core_plugin_manager::reset_caches();
        return $results;
    }

    /**
     * Adds the badges and the progress bar to an existing course, and turns the completion of the course on.
     *
     * Nothing is removed or changed: a badge the course has (by name) stays as it is; the block already on the page stays.
     *
     * @param \stdClass $course
     * @param array[] $badges Each: key, name, description, cmids (int[]), draftitemid (int, 0: no image), filename.
     * @param bool $progressblock
     * @return array ['completion' => string, 'badges' => array[], 'block' => array]
     */
    public static function apply_to_course(\stdClass $course, array $badges, bool $progressblock): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        $state = self::site_state();
        $out = ['completion' => 'notneeded', 'badges' => [], 'block' => ['action' => 'notasked', 'reason' => '']];
        $needscompletion = $progressblock || !empty($badges);
        if ($needscompletion) {
            if (!$state['completion']) {
                $out['completion'] = 'siteoff';
            } else if (empty($course->enablecompletion)) {
                $DB->set_field('course', 'enablecompletion', 1, ['id' => $course->id]);
                $course->enablecompletion = 1;
                rebuild_course_cache($course->id, true);
                $out['completion'] = 'enabled';
            } else {
                $out['completion'] = 'already';
            }
        }
        foreach ($badges as $badge) {
            $out['badges'][] = self::apply_badge($course, $badge, $state);
        }
        if ($progressblock) {
            $out['block'] = self::apply_block($course, $state);
        }
        return $out;
    }

    /**
     * One badge: created (inactive, then activated) when the course does not have one by that name.
     *
     * @param \stdClass $course
     * @param array $badge
     * @param array $state
     * @return array ['key' => string, 'action' => 'created'|'existing'|'skipped', 'reason' => string, 'badgeid' => int,
     *     'active' => bool]
     */
    protected static function apply_badge(\stdClass $course, array $badge, array $state): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        require_once($CFG->dirroot . '/badges/criteria/award_criteria.php');
        $key = (string) $badge['key'];
        $result = ['key' => $key, 'action' => 'skipped', 'reason' => '', 'badgeid' => 0, 'active' => false];
        $name = trim((string) $badge['name']);
        $existing = null;
        foreach ($DB->get_records('badge', ['courseid' => $course->id, 'type' => BADGE_TYPE_COURSE], 'id') as $r) {
            if (\core_text::strtolower(trim((string) $r->name)) === \core_text::strtolower($name)) {
                $existing = $r;
                break;
            }
        }
        if ($existing) {
            $status = (int) $existing->status;
            return ['key' => $key, 'action' => 'existing', 'reason' => '', 'badgeid' => (int) $existing->id,
                'active' => $status === BADGE_STATUS_ACTIVE || $status === BADGE_STATUS_ACTIVE_LOCKED];
        }
        if (!$state['badges']) {
            $result['reason'] = 'badgesoff';
            return $result;
        }
        if (!$state['coursebadges']) {
            $result['reason'] = 'coursebadgesoff';
            return $result;
        }
        if (!$state['completion']) {
            $result['reason'] = 'completionoff';
            return $result;
        }
        // The activities that award it: only the ones that exist in the course now.
        $modinfo = get_fast_modinfo($course);
        $cmids = [];
        foreach ((array) ($badge['cmids'] ?? []) as $cmid) {
            $cm = $modinfo->cms[(int) $cmid] ?? null;
            if ($cm && empty($cm->deletioninprogress)) {
                $cmids[] = (int) $cmid;
            }
        }
        $cmids = array_values(array_unique($cmids));
        if (!$cmids) {
            $result['reason'] = 'noactivities';
            return $result;
        }
        $imagefile = null;
        if (!empty($badge['draftitemid'])) {
            $imagefile = self::image_to_temp((int) $badge['draftitemid'], (string) ($badge['filename'] ?? ''));
        }
        $now = time();
        $id = $DB->insert_record('badge', (object) [
            'name' => \core_text::substr($name, 0, 255),
            'description' => \core_text::substr((string) $badge['description'], 0, 2000),
            'timecreated' => $now, 'timemodified' => $now,
            'usercreated' => get_admin()->id, 'usermodified' => get_admin()->id,
            'issuername' => \core_text::substr((string) ($badge['issuer'] ?? 'MiwLab'), 0, 255), 'issuerurl' => $CFG->wwwroot,
            'type' => BADGE_TYPE_COURSE, 'courseid' => $course->id,
            'message' => '<p>%badgename%</p><p>%badgelink%</p>', 'messagesubject' => $name,
            'attachment' => 1, 'notification' => 0, 'status' => BADGE_STATUS_INACTIVE,
            'imagecaption' => \core_text::substr($name, 0, 255),
        ]);
        // The criteria exactly as the package brings them: all of the conditions, and the completion of each activity.
        $overall = $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
            'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
        $activity = $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY,
            'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
        unset($overall);
        foreach ($cmids as $cmid) {
            $DB->insert_record(
                'badge_criteria_param',
                (object) ['critid' => $activity, 'name' => 'module_' . $cmid, 'value' => $cmid]
            );
        }
        $new = new \core_badges\badge($id);
        if ($imagefile !== null) {
            badges_process_badge_image($new, $imagefile);
        }
        $new->set_status(BADGE_STATUS_ACTIVE);
        return ['key' => $key, 'action' => 'created', 'reason' => '', 'badgeid' => (int) $id, 'active' => true];
    }

    /**
     * Copies the verified image the plugin was sent to a temporary file for Moodle to scale (PNG, JPEG or GIF, up to 2 MB).
     *
     * @param int $draftitemid
     * @param string $filename
     * @return string|null The path, or null when the file is not an image that is accepted (the badge goes without a picture).
     */
    protected static function image_to_temp(int $draftitemid, string $filename): ?string {
        $file = restorer::draft_file($draftitemid, $filename);
        if ($file->get_filesize() > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $bytes = $file->get_content();
        if (!preg_match('/^(\x89PNG\r\n\x1a\n|\xff\xd8\xff|GIF8[79]a)/', $bytes)) {
            return null;
        }
        $path = make_request_directory() . '/badge.img';
        file_put_contents($path, $bytes);
        return $path;
    }

    /**
     * The progress bar block on the course page: added once, when it is installed and enabled.
     *
     * @param \stdClass $course
     * @param array $state
     * @return array ['action' => 'added'|'existing'|'skipped', 'reason' => string]
     */
    protected static function apply_block(\stdClass $course, array $state): array {
        global $CFG, $DB;
        if (!$state['progressblockinstalled']) {
            return ['action' => 'skipped', 'reason' => 'notinstalled'];
        }
        if (!$state['progressblockenabled']) {
            return ['action' => 'skipped', 'reason' => 'disabled'];
        }
        $contextid = \context_course::instance($course->id)->id;
        if ($DB->record_exists('block_instances', ['parentcontextid' => $contextid, 'blockname' => self::PROGRESS_BLOCK])) {
            return ['action' => 'existing', 'reason' => ''];
        }
        require_once($CFG->libdir . '/blocklib.php');
        $page = new \moodle_page();
        $page->set_context(\context_course::instance($course->id));
        $page->set_pagetype('course-view-topics');
        $page->set_url('/course/view.php', ['id' => $course->id]);
        $page->set_course($course);
        $page->blocks->add_regions(['side-pre']);
        $page->blocks->add_block(self::PROGRESS_BLOCK, 'side-pre', 0, false, 'course-view-*');
        return ['action' => 'added', 'reason' => ''];
    }
}
