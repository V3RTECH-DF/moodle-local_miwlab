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

namespace local_miwlab\external;

/**
 * Version of the plugin and what it offers, so that the MiwLab detects it.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_info extends base {
    /** @var string[] What the plugin offers, by name. A new feature adds a name; a name never changes meaning. */
    const FEATURES = [
        'restore', 'restorestatus', 'findcourses', 'completion', 'feedback', 'moduletypes', 'courseformats', 'replace',
        'hidecourse', 'coursestate', 'blocks', 'badges', 'contentbackup', 'certificatetemplates', 'uploadlimits', 'forumtopics',
        'coursesoverview', 'fullbackup', 'safeupdate',
        // 1.10.0: stamp of the activities (issue 254), competencies (252), scales (250), course completion criteria by activity
        // and by grade in the safe update (247).
        'stampactivities', 'findcoursesbystamp', 'scales', 'competencies', 'completioncriteria',
        // 1.10.2: a competency "achieved when any child is" and the competencies of the MiwLab moved under the parent it asks
        // (issue 252).
        'competencyrules',
        'updatepagefiles',
        // 1.12.0 (issue 333): badges and the progress bar added to a course that exists ("gamification"), and the site switches
        // reported by get_info and turned on after the person confirmed ("siteswitches").
        'gamification', 'siteswitches',
        // 1.13.0: the opening topics of the forums created by the safe update (issue 347) and the summary of the course carried by
        // it (issue 348).
        'updateforumtopics', 'updatecoursesummary',
        // 1.14.0: the image of the course carried by the safe update, replaced in place when its bytes differ (issue 355).
        'updatecourseimage',
    ];

    /** @var string[] Add-ons of Moodle (components of other developers) whose presence the MiwLab asks. */
    const ADDONS = ['availability_coursecompleted'];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the version of the plugin and of Moodle, and the features.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;
        self::validate_context(\context_system::instance());
        $plugin = \core_plugin_manager::instance()->get_plugin_info('local_miwlab');
        $context = \context_system::instance();
        return [
            'component' => 'local_miwlab',
            'version' => (int) $plugin->versiondisk,
            'release' => (string) $plugin->release,
            'moodleversion' => (string) $CFG->version,
            'moodlerelease' => (string) $CFG->release,
            'features' => self::FEATURES,
            'competenciesenabled' => (bool) get_config('core_competency', 'enabled'),
            'siteconfig' => self::siteconfig(),
            'addons' => self::addons(),
            'systemcapabilities' => [
                'restorecourse' => has_capability('local/miwlab:restorecourse', $context),
                'viewreports' => has_capability('local/miwlab:viewreports', $context),
                'viewmoduletypes' => has_capability('local/miwlab:viewmoduletypes', $context),
                'manageconfig' => has_capability('local/miwlab:manageconfig', $context),
            ],
        ];
    }

    /**
     * What the site has on that the competencies and the gamification depend on.
     *
     * @return array
     */
    protected static function siteconfig(): array {
        $state = \local_miwlab\local\gamification::site_state();
        return [
            'badges' => $state['badges'],
            'coursebadges' => $state['coursebadges'],
            'completion' => $state['completion'],
            'progressblockinstalled' => $state['progressblockinstalled'],
            'progressblockenabled' => $state['progressblockenabled'],
        ];
    }

    /**
     * Whether each add-on the MiwLab asks about is installed, enabled and in which version.
     *
     * @return array
     */
    protected static function addons(): array {
        $manager = \core_plugin_manager::instance();
        $addons = [];
        foreach (self::ADDONS as $component) {
            $info = $manager->get_plugin_info($component);
            // Installed means in the database too: the code can be on disk and waiting for the upgrade.
            $installed = $info && !empty($info->versiondb);
            $addons[] = [
                'component' => $component,
                'installed' => (bool) $installed,
                'enabled' => $installed && $info->is_enabled() !== false,
                'version' => $installed ? (int) $info->versiondb : 0,
                'release' => $installed ? (string) $info->release : '',
            ];
        }
        return $addons;
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'component' => new external_value(PARAM_COMPONENT, 'Always local_miwlab'),
            'version' => new external_value(PARAM_INT, 'Version of the plugin (YYYYMMDDXX)'),
            'release' => new external_value(PARAM_TEXT, 'Release of the plugin'),
            'moodleversion' => new external_value(PARAM_TEXT, 'Version of Moodle'),
            'moodlerelease' => new external_value(PARAM_TEXT, 'Release of Moodle'),
            'features' => new external_multiple_structure(new external_value(PARAM_ALPHANUMEXT, 'Feature name')),
            'competenciesenabled' => new external_value(PARAM_BOOL, 'The competencies of Moodle are enabled on the site'),
            'siteconfig' => new external_single_structure([
                'badges' => new external_value(PARAM_BOOL, 'The badges of the site are enabled'),
                'coursebadges' => new external_value(PARAM_BOOL, 'Badges in courses are enabled'),
                'completion' => new external_value(PARAM_BOOL, 'Completion tracking is enabled on the site'),
                'progressblockinstalled' => new external_value(
                    PARAM_BOOL,
                    'The progress bar block (block_completion_progress) is installed'
                ),
                'progressblockenabled' => new external_value(PARAM_BOOL, 'The progress bar block is installed and enabled'),
            ], 'Site switches the gamification depends on'),
            'addons' => new external_multiple_structure(new external_single_structure([
                'component' => new external_value(PARAM_COMPONENT, 'Component of the add-on'),
                'installed' => new external_value(PARAM_BOOL, 'Installed in the database (not only on disk)'),
                'enabled' => new external_value(PARAM_BOOL, 'Installed and enabled'),
                'version' => new external_value(PARAM_INT, 'Version installed (YYYYMMDDXX); 0 when it is not'),
                'release' => new external_value(PARAM_RAW, 'Release installed; empty when it is not'),
            ]), 'Add-ons the MiwLab asks about'),
            'systemcapabilities' => new external_single_structure([
                'restorecourse' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:restorecourse at site level'),
                'viewreports' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:viewreports at site level'),
                'viewmoduletypes' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:viewmoduletypes at site level'),
                'manageconfig' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:manageconfig at site level'),
            ]),
        ]);
    }
}
