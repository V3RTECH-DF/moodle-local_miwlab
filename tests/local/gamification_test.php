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

use local_miwlab\external\apply_gamification;
use local_miwlab\external\base;
use local_miwlab\external\enable_features;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\local\gamification::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\apply_gamification::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_miwlab\external\enable_features::class)]
/**
 * Tests of the badges and the progress bar on a course that exists, and of the site switches turned on after
 * confirming (issue 333).
 *
 * @package    local_miwlab
 * @category   test
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_miwlab\local\gamification
 * @covers     \local_miwlab\external\apply_gamification
 * @covers     \local_miwlab\external\enable_features
 */
final class gamification_test extends \advanced_testcase {
    /**
     * A course with completion and a page that tracks it.
     *
     * @param int $completion Whether the course has completion tracking on (1) or off (0).
     * @return array [course, cmid]
     */
    protected function course_with_a_page(int $completion = 1): array {
        global $DB;
        // The activity is made with completion tracking; a course that does not have it on is turned off afterwards.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page = $this->getDataGenerator()->create_module(
            'page',
            ['course' => $course->id, 'completion' => 2, 'completionview' => 1]
        );
        if (!$completion) {
            $DB->set_field('course', 'enablecompletion', 0, ['id' => $course->id]);
            $course->enablecompletion = 0;
        }
        return [$course, (int) $page->cmid];
    }

    /**
     * One badge as the MiwLab sends it.
     *
     * @param int[] $cmids
     * @param string $name
     * @return array
     */
    protected function badge(array $cmids, string $name = 'Planejar acampamentos'): array {
        return [
            'key' => 'C01', 'name' => $name, 'description' => 'Concedido a quem demonstrou a competência.',
            'issuer' => 'Associação', 'cmids' => $cmids, 'draftitemid' => 0, 'filename' => '',
        ];
    }

    public function test_site_state_follows_the_configuration(): void {
        $this->resetAfterTest();
        set_config('enablebadges', 0);
        set_config('badges_allowcoursebadges', 0);
        set_config('enablecompletion', 0);
        set_config('enabled', 0, 'core_competency');
        $off = gamification::site_state();
        $this->assertFalse($off['badges']);
        $this->assertFalse($off['coursebadges']);
        $this->assertFalse($off['completion']);
        $this->assertFalse($off['competencies']);
        // The block of another developer may or may not be in this test Moodle: the answer follows what is installed,
        // and not installed is never enabled.
        $info = \core_plugin_manager::instance()->get_plugin_info('block_completion_progress');
        $installed = $info && !empty($info->versiondb);
        $this->assertSame((bool) $installed, $off['progressblockinstalled']);
        $visible = (int) $GLOBALS['DB']->get_field('block', 'visible', ['name' => 'completion_progress']) === 1;
        $this->assertSame($installed && $visible, $off['progressblockenabled']);
        // CONTROL: turned on, the state says so.
        set_config('enablebadges', 1);
        $this->assertTrue(gamification::site_state()['badges']);
    }

    public function test_enable_turns_each_switch_on_once_and_never_off(): void {
        $this->resetAfterTest();
        set_config('enablebadges', 0);
        set_config('badges_allowcoursebadges', 0);
        set_config('enablecompletion', 0);
        set_config('enabled', 0, 'core_competency');
        $asked = ['competencies', 'badges', 'coursebadges', 'completion'];
        $results = array_column(gamification::enable($asked), 'result', 'feature');
        $this->assertSame(
            ['competencies' => 'enabled', 'badges' => 'enabled', 'coursebadges' => 'enabled', 'completion' => 'enabled'],
            $results
        );
        $state = gamification::site_state();
        $this->assertTrue($state['competencies'] && $state['badges'] && $state['coursebadges'] && $state['completion']);
        // Asking again changes nothing and says so.
        $again = array_column(gamification::enable(['badges', 'completion']), 'result', 'feature');
        $this->assertSame(['badges' => 'already', 'completion' => 'already'], $again);
    }

    public function test_enable_refuses_what_is_not_on_the_list_and_never_installs_the_block(): void {
        $this->resetAfterTest();
        set_config('enablewebservices', 0);
        $results = array_column(gamification::enable(['enablewebservices', 'progressblock']), 'result', 'feature');
        $this->assertSame('unknown', $results['enablewebservices']);
        if (gamification::site_state()['progressblockinstalled']) {
            $this->assertContains($results['progressblock'], ['enabled', 'already']);
        } else {
            $this->assertSame('notinstalled', $results['progressblock']);
        }
        // The name that is not on the list touched nothing.
        $this->assertEquals(0, get_config('core', 'enablewebservices'));
    }

    public function test_creates_the_badge_with_the_criteria_active_and_repeating_adds_nothing(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cmid] = $this->course_with_a_page();
        $result = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('already', $result['completion']);
        $this->assertSame('created', $result['badges'][0]['action']);
        $this->assertTrue($result['badges'][0]['active']);
        $badge = $DB->get_record('badge', ['courseid' => $course->id], '*', MUST_EXIST);
        $this->assertEquals(BADGE_STATUS_ACTIVE, $badge->status);
        $this->assertEquals(BADGE_TYPE_COURSE, $badge->type);
        $this->assertTrue((new \core_badges\badge($badge->id))->has_criteria());
        $params = $DB->get_records_sql(
            'SELECT p.name, p.value FROM {badge_criteria_param} p JOIN {badge_criteria} c ON c.id = p.critid WHERE c.badgeid = ?',
            [$badge->id]
        );
        $this->assertSame(['module_' . $cmid], array_keys($params));
        // Repeating: nothing is created again, the badge stays.
        $second = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('existing', $second['badges'][0]['action']);
        $this->assertSame(1, $DB->count_records('badge', ['courseid' => $course->id]));
        // The name is compared without case and without the spaces around.
        $third = gamification::apply_to_course($course, [$this->badge([$cmid], '  planejar ACAMPAMENTOS ')], false);
        $this->assertSame('existing', $third['badges'][0]['action']);
        $this->assertSame(1, $DB->count_records('badge', ['courseid' => $course->id]));
    }

    public function test_a_badge_already_awarded_is_never_touched(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cmid] = $this->course_with_a_page();
        gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $badge = $DB->get_record('badge', ['courseid' => $course->id], '*', MUST_EXIST);
        $user = $this->getDataGenerator()->create_user();
        $DB->insert_record('badge_issued', (object) ['badgeid' => $badge->id, 'userid' => $user->id, 'uniquehash' => sha1('x'),
            'dateissued' => time(), 'visible' => 1]);
        $DB->set_field('badge', 'description', 'Texto que a pessoa mudou', ['id' => $badge->id]);
        $result = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('existing', $result['badges'][0]['action']);
        $this->assertSame(1, $DB->count_records('badge_issued', ['badgeid' => $badge->id]));
        $this->assertSame('Texto que a pessoa mudou', $DB->get_field('badge', 'description', ['id' => $badge->id]));
        $this->assertSame(1, $DB->count_records('badge', ['courseid' => $course->id]));
    }

    public function test_each_reason_it_does_not_go_is_told(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cmid] = $this->course_with_a_page();
        [, $outsider] = $this->course_with_a_page();
        // An activity that is not in this course is not one that awards.
        $r = gamification::apply_to_course($course, [$this->badge([$outsider])], false);
        $this->assertSame(['skipped', 'noactivities'], [$r['badges'][0]['action'], $r['badges'][0]['reason']]);
        set_config('enablebadges', 0);
        $r = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('badgesoff', $r['badges'][0]['reason']);
        set_config('enablebadges', 1);
        set_config('badges_allowcoursebadges', 0);
        $r = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('coursebadgesoff', $r['badges'][0]['reason']);
        set_config('badges_allowcoursebadges', 1);
        set_config('enablecompletion', 0);
        $r = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('completionoff', $r['badges'][0]['reason']);
        $this->assertSame('siteoff', $r['completion']);
        $this->assertSame(0, $DB->count_records('badge', ['courseid' => $course->id]));
        // CONTROL: with everything on, the same badge goes.
        set_config('enablecompletion', 1);
        $r = gamification::apply_to_course($course, [$this->badge([$cmid])], false);
        $this->assertSame('created', $r['badges'][0]['action']);
    }

    public function test_the_completion_of_the_course_is_turned_on_and_the_block_that_is_not_installed_is_told(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cmid] = $this->course_with_a_page(0);
        $r = gamification::apply_to_course($course, [$this->badge([$cmid])], true);
        $this->assertSame('enabled', $r['completion']);
        $this->assertEquals(1, $DB->get_field('course', 'enablecompletion', ['id' => $course->id]));
        // Without the block of another developer the plugin says so and does not install it; with it, the block is put
        // in the course.
        $this->assertSame(
            gamification::site_state()['progressblockinstalled'] ? ['added', ''] : ['skipped', 'notinstalled'],
            [$r['block']['action'], $r['block']['reason']]
        );
        $this->assertSame('created', $r['badges'][0]['action']);
        // Nothing asked: nothing said, and the completion of a course that did not need it is left alone.
        [$other] = $this->course_with_a_page(0);
        $none = gamification::apply_to_course($other, [], false);
        $this->assertSame(['notneeded', 'notasked'], [$none['completion'], $none['block']['action']]);
        $this->assertEquals(0, $DB->get_field('course', 'enablecompletion', ['id' => $other->id]));
    }

    public function test_the_picture_of_the_badge_is_scaled_from_the_draft_area(): void {
        global $USER;
        $this->resetAfterTest();
        $this->setAdminUser();
        [$course, $cmid] = $this->course_with_a_page();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $draft = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $draft, 'filepath' => '/', 'filename' => 'cracha.png',
        ], $png);
        $badge = $this->badge([$cmid]);
        $badge['draftitemid'] = $draft;
        $badge['filename'] = 'cracha.png';
        $r = gamification::apply_to_course($course, [$badge], false);
        $this->assertSame('created', $r['badges'][0]['action']);
        $context = \context_course::instance($course->id);
        $badgeid = $r['badges'][0]['badgeid'];
        $files = get_file_storage()->get_area_files($context->id, 'badges', 'badgeimage', $badgeid, 'filename', false);
        $this->assertContains('f3.png', array_map(fn($f) => $f->get_filename(), $files));
        // A file that is not an image is not taken: the badge goes without the picture, and goes.
        $text = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($USER->id)->id, 'component' => 'user', 'filearea' => 'draft',
            'itemid' => $text, 'filepath' => '/', 'filename' => 'falso.png',
        ], '<script>alert(1)</script>');
        $fake = $this->badge([$cmid], 'Outro crachá');
        $fake['draftitemid'] = $text;
        $fake['filename'] = 'falso.png';
        $r2 = gamification::apply_to_course($course, [$fake], false);
        $this->assertSame('created', $r2['badges'][0]['action']);
        $badgeid2 = $r2['badges'][0]['badgeid'];
        $this->assertCount(
            0,
            get_file_storage()->get_area_files($context->id, 'badges', 'badgeimage', $badgeid2, 'filename', false)
        );
    }

    public function test_the_progress_block_is_enabled_and_added_once_when_it_is_installed(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        if (!gamification::site_state()['progressblockinstalled']) {
            $this->markTestSkipped('block_completion_progress (of another developer) is not installed in this Moodle.');
        }
        [$course] = $this->course_with_a_page();
        $contextid = \context_course::instance($course->id)->id;
        // Installed but disabled: the block is not put in the course, and it says why.
        $DB->set_field('block', 'visible', 0, ['name' => 'completion_progress']);
        $r = gamification::apply_to_course($course, [], true);
        $this->assertSame(['skipped', 'disabled'], [$r['block']['action'], $r['block']['reason']]);
        $where = ['parentcontextid' => $contextid, 'blockname' => 'completion_progress'];
        $this->assertFalse($DB->record_exists('block_instances', $where));
        // Turned on after the person confirmed: enabled once, "already" the second time.
        $this->assertSame([['feature' => 'progressblock', 'result' => 'enabled']], gamification::enable(['progressblock']));
        $this->assertSame([['feature' => 'progressblock', 'result' => 'already']], gamification::enable(['progressblock']));
        $r = gamification::apply_to_course($course, [], true);
        $this->assertSame('added', $r['block']['action']);
        $again = gamification::apply_to_course($course, [], true);
        $this->assertSame('existing', $again['block']['action']);
        $this->assertSame(1, $DB->count_records('block_instances', $where));
    }

    /**
     * An account with only the given plugin and core capabilities, at the given context.
     *
     * @param \context $context
     * @param string[] $capabilities
     */
    protected function account_with(\context $context, array $capabilities): void {
        $user = $this->getDataGenerator()->create_user();
        $roleid = create_role('Gam' . random_int(1, 99999), 'gam' . random_int(1, 99999), '');
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
        foreach ($capabilities as $capability) {
            assign_capability($capability, CAP_ALLOW, $roleid, \context_system::instance()->id, true);
        }
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser($user);
    }

    public function test_the_functions_need_their_own_capability(): void {
        $this->resetAfterTest();
        [$course, $cmid] = $this->course_with_a_page();
        $this->account_with(\context_system::instance(), []);
        try {
            enable_features::execute(['badges']);
            $this->fail('local/miwlab:manageconfig is required');
        } catch (\required_capability_exception $e) {
            $this->assertStringContainsString('Turn on, for the MiwLab', $e->getMessage());
        }
        try {
            apply_gamification::execute($course->id, [$this->badge([$cmid])], false);
            $this->fail('local/miwlab:managegamification is required');
        } catch (\required_capability_exception $e) {
            $this->assertTrue(true);
        }
        // With the plugin capability but not the one of Moodle to create a badge, a badge is refused.
        $this->account_with(\context_system::instance(), ['local/miwlab:managegamification']);
        try {
            apply_gamification::execute($course->id, [$this->badge([$cmid])], false);
            $this->fail('moodle/badges:createbadge is required');
        } catch (\required_capability_exception $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, $GLOBALS['DB']->count_records('badge', ['courseid' => $course->id]));
        // CONTROL: with all of them it works, cleaned as the web service layer does.
        $this->account_with(\context_system::instance(), [
            'local/miwlab:managegamification', 'moodle/badges:createbadge', 'moodle/badges:configurecriteria',
            'local/miwlab:manageconfig',
        ]);
        $r = base::clean_returnvalue(
            apply_gamification::execute_returns(),
            apply_gamification::execute($course->id, [$this->badge([$cmid])], false)
        );
        $this->assertSame('created', $r['badges'][0]['action']);
        set_config('enablebadges', 0);
        $e = base::clean_returnvalue(enable_features::execute_returns(), enable_features::execute(['badges']));
        $this->assertSame([['feature' => 'badges', 'result' => 'enabled']], $e['results']);
    }
}
