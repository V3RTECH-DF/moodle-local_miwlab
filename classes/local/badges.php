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
 * The course badges a MiwLab package brings, activated once the package is restored.
 *
 * Moodle always restores course badges inactive (restore_badges_structure_step), and an inactive badge is never awarded. The
 * MiwLab package carries one badge per competency, awarded when the activity that assesses it is completed with the passing
 * grade; the plugin activates the badges that the restore it ran brought in, so that a course sent by the MiwLab is ready to
 * award them. Badges that were already in the course, badges without criteria and the ones of any other restore are left
 * as they are. Nothing happens when badges or course badges are disabled on the site (the restore brings none then).
 *
 * When the content of a course is replaced, the badges of the old content are deleted first (Moodle's own deletion of the
 * content would archive each one as a site badge, one more at every replacement); a replacement only happens when nobody has
 * progress, so none was awarded, and an awarded one refuses the deletion all the same. When the replacement fails, the backup
 * brings them back inactive, and they get the status they had.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class badges {
    /**
     * The ids of the badges of a course, in order, before the restore (the ones not to touch afterwards).
     *
     * @param int $courseid
     * @return int[]
     */
    public static function of_course(int $courseid): array {
        global $DB;
        $sql = 'SELECT id FROM {badge} WHERE courseid = :courseid ORDER BY id';
        return array_map('intval', $DB->get_fieldset_sql($sql, ['courseid' => $courseid]));
    }

    /**
     * Refuses when a badge of the course was awarded to somebody (progress: the badge would be lost).
     *
     * @param int $courseid
     * @throws \moodle_exception error:hasprogress
     */
    public static function require_none_awarded(int $courseid): void {
        global $DB;
        $awarded = (int) $DB->count_records_sql(
            "SELECT COUNT(DISTINCT bi.userid) FROM {badge_issued} bi JOIN {badge} b ON b.id = bi.badgeid
              WHERE b.courseid = :courseid",
            ['courseid' => $courseid]
        );
        if ($awarded > 0) {
            $people = replacer::people($courseid);
            throw new \moodle_exception('error:hasprogress', 'local_miwlab', '', (object) [
                'people' => max((int) $people['withprogress'], $awarded),
                'enrolled' => $people['enrolled'],
            ]);
        }
    }

    /**
     * Deletes the badges of a course, for good (not archived), before its content is replaced or rolled back.
     *
     * @param int $courseid
     * @return array[] What each badge was, in order: ['name' => string, 'status' => int].
     * @throws \moodle_exception error:hasprogress, when one of them was awarded (nothing is deleted then).
     */
    public static function remove_from_course(int $courseid): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        self::require_none_awarded($courseid);
        $records = $DB->get_records('badge', ['courseid' => $courseid], 'id', 'id, name, status');
        $removed = [];
        foreach ($records as $record) {
            $removed[] = ['name' => (string) $record->name, 'status' => (int) $record->status];
            (new \core_badges\badge((int) $record->id))->delete(false);
        }
        return $removed;
    }

    /**
     * After a failed replacement was rolled back: the badges the backup brought back (inactive) get the status they had, matched
     * by name, in order.
     *
     * @param int $courseid
     * @param array[] $removed What remove_from_course() returned.
     * @return int How many badges changed status.
     */
    public static function put_back_statuses(int $courseid, array $removed): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        $changed = 0;
        foreach ($DB->get_records('badge', ['courseid' => $courseid], 'id', 'id, name, status') as $record) {
            foreach ($removed as $i => $was) {
                if ($was['name'] !== (string) $record->name) {
                    continue;
                }
                unset($removed[$i]);
                if ($was['status'] !== (int) $record->status) {
                    $badge = new \core_badges\badge((int) $record->id);
                    if (trim((string) $badge->issuerurl) === '') {
                        $badge->issuerurl = $CFG->wwwroot;
                    }
                    $badge->set_status($was['status']);
                    $changed++;
                }
                break;
            }
        }
        return $changed;
    }

    /**
     * Activates the inactive course badges with criteria that the restore brought into the course.
     *
     * A badge without an issuer address gets the address of the site (the issuer is this Moodle).
     *
     * @param int $courseid
     * @param int[] $before The badges the course had before the restore.
     * @return int How many badges were activated.
     */
    public static function activate_restored(int $courseid, array $before): int {
        global $CFG, $DB;
        if (empty($CFG->enablebadges) || empty($CFG->badges_allowcoursebadges)) {
            return 0;
        }
        require_once($CFG->libdir . '/badgeslib.php');
        $records = $DB->get_records(
            'badge',
            ['courseid' => $courseid, 'type' => BADGE_TYPE_COURSE, 'status' => BADGE_STATUS_INACTIVE],
            'id',
            'id'
        );
        $activated = 0;
        foreach ($records as $record) {
            if (in_array((int) $record->id, $before, true)) {
                continue;
            }
            $badge = new \core_badges\badge((int) $record->id);
            if (!$badge->has_criteria()) {
                continue;
            }
            if (trim((string) $badge->issuerurl) === '') {
                $badge->issuerurl = $CFG->wwwroot;
            }
            $badge->set_status(BADGE_STATUS_ACTIVE);
            $activated++;
        }
        return $activated;
    }
}
