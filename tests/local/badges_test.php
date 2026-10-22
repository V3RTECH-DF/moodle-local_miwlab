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

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\badges::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\restorer::class)]
/**
 * Tests of the activation of the course badges a MiwLab package brings, once the plugin restores it.
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\badges
 * @covers     \local_miwlab\local\restorer
 */
final class badges_test extends \advanced_testcase {
    /**
     * A course badge, inactive, with the overall criterion and the completion of the given activity (as the package carries
     * it), or without criteria.
     *
     * @param int $courseid
     * @param int|null $cmid
     * @param string $issuerurl
     * @return int
     */
    protected function badge(int $courseid, ?int $cmid, string $issuerurl = ''): int {
        global $CFG, $DB;
        require_once($CFG->libdir . '/badgeslib.php');
        $now = time();
        $id = $DB->insert_record('badge', (object) [
            'name' => 'Crachá ' . random_int(1, 99999), 'description' => 'D', 'timecreated' => $now, 'timemodified' => $now,
            'usercreated' => get_admin()->id, 'usermodified' => get_admin()->id, 'issuername' => 'Associação Modelo de Formação',
            'issuerurl' => $issuerurl, 'type' => BADGE_TYPE_COURSE, 'courseid' => $courseid, 'message' => 'M',
            'messagesubject' => 'S',
            'attachment' => 1, 'notification' => 0, 'status' => BADGE_STATUS_INACTIVE,
        ]);
        if ($cmid !== null) {
            $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_OVERALL,
                'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
            $crit = $DB->insert_record('badge_criteria', (object) ['badgeid' => $id, 'criteriatype' => BADGE_CRITERIA_TYPE_ACTIVITY,
                'method' => BADGE_CRITERIA_AGGREGATION_ALL, 'description' => '', 'descriptionformat' => 1]);
            $DB->insert_record('badge_criteria_param', (object) ['critid' => $crit, 'name' => 'module_' . $cmid, 'value' => $cmid]);
        }
        return $id;
    }

    public function test_activates_the_badges_the_restore_brought(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => 2, 'completionview' => 1]
        );
        $before = $this->badge($course->id, $page->cmid);
        $brought = $this->badge($course->id, $page->cmid);
        $withurl = $this->badge($course->id, $page->cmid, 'https://example.invalid/');
        $nocriteria = $this->badge($course->id, null);
        // Moodle 4.1 (Open Badges 2) loads every badge with the site as the issuer, and keeps it when the badge is saved: the
        // address the plugin leaves alone is the one Moodle itself gives.
        $semmudar = (new \core_badges\badge($withurl))->issuerurl;
        $this->assertSame(2, badges::activate_restored($course->id, [$before]));
        $status = $DB->get_records_menu('badge', ['courseid' => $course->id], '', 'id, status');
        // The one the course already had and the one without criteria stay inactive.
        $this->assertEquals(BADGE_STATUS_INACTIVE, $status[$before]);
        $this->assertEquals(BADGE_STATUS_INACTIVE, $status[$nocriteria]);
        $this->assertEquals(BADGE_STATUS_ACTIVE, $status[$brought]);
        $this->assertEquals(BADGE_STATUS_ACTIVE, $status[$withurl]);
        // The issuer address: the site's when empty; the one it had otherwise.
        // Moodle 4.1 keeps the address with a final slash when it saves the badge; the later versions, as given.
        $this->assertSame(rtrim($CFG->wwwroot, '/'), rtrim($DB->get_field('badge', 'issuerurl', ['id' => $brought]), '/'));
        $this->assertSame($semmudar, $DB->get_field('badge', 'issuerurl', ['id' => $withurl]));
        $this->assertSame([$before, $brought, $withurl, $nocriteria], badges::of_course($course->id));
    }

    public function test_nothing_with_badges_or_course_badges_disabled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $id = $this->badge($course->id, $page->cmid);
        set_config('badges_allowcoursebadges', 0);
        $this->assertSame(0, badges::activate_restored($course->id, []));
        set_config('badges_allowcoursebadges', 1);
        set_config('enablebadges', 0);
        $this->assertSame(0, badges::activate_restored($course->id, []));
        $this->assertEquals(BADGE_STATUS_INACTIVE, $DB->get_field('badge', 'status', ['id' => $id]));
        // CONTROL: with both on, it is activated.
        set_config('enablebadges', 1);
        $this->assertSame(1, badges::activate_restored($course->id, []));
    }

    public function test_the_plugin_restore_brings_the_badge_active(): void {
        $badge = $this->restore_with_a_badge(true);
        // Moodle restores it inactive; the plugin activates it.
        $this->assertEquals(BADGE_STATUS_ACTIVE, $badge->status);
    }

    public function test_without_configurecriteria_the_restore_finishes_and_leaves_the_badge_inactive(): void {
        $badge = $this->restore_with_a_badge(false);
        $this->assertEquals(BADGE_STATUS_INACTIVE, $badge->status);
    }

    /**
     * Restores, through the plugin, a package with one badge, as an account with the capabilities of the restore and, or not,
     * moodle/badges:configurecriteria (what activating a badge needs).
     *
     * @param bool $configurecriteria
     * @return \stdClass The badge of the restored course.
     */
    protected function restore_with_a_badge(bool $configurecriteria): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $origin = $generator->create_course(['fullname' => 'Origem com crachá', 'enablecompletion' => 1]);
        $page = $generator->create_module('page', ['course' => $origin->id, 'completion' => 2, 'completionview' => 1]);
        $this->badge($origin->id, $page->cmid);
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $origin->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            get_admin()->id
        );
        $bc->execute_plan();
        $backup = $bc->get_results()['backup_destination'];
        $bc->destroy();
        // The MiwLab package declares no user data (root setting "users" 0) and carries the badges; a backup made by Moodle with
        // users on, restored by an account without moodle/restore:userinfo, would lose the badges to the "users" dependency. The
        // package of the test is made like the MiwLab one: the backup, with the setting "users" turned to 0.
        $packer = get_file_packer('application/vnd.moodle.backup');
        $dir = make_request_directory();
        $backup->extract_to_pathname($packer, $dir);
        $index = file_get_contents($dir . '/moodle_backup.xml');
        $index = preg_replace('#(<level>root</level>\s*<name>users</name>\s*<value>)1(</value>)#', '${1}0${2}', $index, 1, $trocas);
        $this->assertSame(1, $trocas);
        file_put_contents($dir . '/moodle_backup.xml', $index);
        $lista = [];
        $arvore = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach (new \RecursiveIteratorIterator($arvore) as $arquivo) {
            $lista[substr($arquivo->getPathname(), strlen($dir) + 1)] = $arquivo->getPathname();
        }
        $category = $generator->create_category();
        $user = $generator->create_user();
        $roleid = create_role('Restaura', 'restaura', '');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $capabilities = array_merge(restorer::CAPABILITIES, $configurecriteria ? [restorer::BADGES_CAPABILITY] : []);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_coursecat::instance($category->id)->id, true);
        }
        role_assign($roleid, $user->id, \context_coursecat::instance($category->id)->id);
        accesslib_clear_all_caches_for_unit_testing();
        $draftitemid = file_get_unused_draft_itemid();
        $usercontext = \context_user::instance($user->id)->id;
        $packer->archive_to_storage($lista, $usercontext, 'user', 'draft', $draftitemid, '/', 'pacote.mbz', $user->id);
        $this->setUser($user);
        $queued = \local_miwlab\external\restore_course::execute($category->id, $draftitemid, '', '');
        $this->runAdhocTasks(\local_miwlab\task\restore_course::class, $user->id);
        $record = $DB->get_record('local_miwlab_restores', ['restoreid' => $queued['restoreid']], '*', MUST_EXIST);
        $this->assertEquals(restorer::STATUS_FINISHED, $record->status, (string) $record->errormessage);
        $badges = $DB->get_records('badge', ['courseid' => $record->courseid]);
        $this->assertCount(1, $badges);
        return reset($badges);
    }
}
