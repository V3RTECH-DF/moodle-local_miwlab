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
 * Turns on, after the person confirmed it in the MiwLab, the site switches the competencies and the gamification need (1.12.0,
 * issue 333).
 *
 * Only a short fixed list, only ever ON: the competencies of Moodle, the badges of the site, the badges of the courses, the
 * completion tracking and the progress bar block when it is installed (a block that is not installed is never installed).
 * Needs local/miwlab:manageconfig at site level (a risk of configuration), which the role of the MiwLab key holds.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enable_features extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'features' => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'competencies, badges, coursebadges, completion or progressblock')
            ),
        ]);
    }

    /**
     * Turns the switches on.
     *
     * @param string[] $features
     * @return array
     */
    public static function execute(array $features): array {
        $params = self::validate_parameters(self::execute_parameters(), ['features' => $features]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/miwlab:manageconfig', $context);
        return ['results' => \local_miwlab\local\gamification::enable($params['features'])];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'results' => new external_multiple_structure(new external_single_structure([
                'feature' => new external_value(PARAM_ALPHA, 'The switch asked'),
                'result' => new external_value(PARAM_ALPHA, 'enabled, already, notinstalled or unknown'),
            ])),
        ]);
    }
}
