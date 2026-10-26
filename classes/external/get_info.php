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
        'coursesoverview',
    ];

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
            'systemcapabilities' => [
                'restorecourse' => has_capability('local/miwlab:restorecourse', $context),
                'viewreports' => has_capability('local/miwlab:viewreports', $context),
                'viewmoduletypes' => has_capability('local/miwlab:viewmoduletypes', $context),
            ],
        ];
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
            'systemcapabilities' => new external_single_structure([
                'restorecourse' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:restorecourse at site level'),
                'viewreports' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:viewreports at site level'),
                'viewmoduletypes' => new external_value(PARAM_BOOL, 'The account holds local/miwlab:viewmoduletypes at site level'),
            ]),
        ]);
    }
}
