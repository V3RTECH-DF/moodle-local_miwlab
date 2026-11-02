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
 * The competency frameworks of Moodle the account can see, with their scale (1.10.0, issue 252). Reads only.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_competency_frameworks extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the frameworks.
     *
     * @return array
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/miwlab:viewcompetencies', $context);
        return ['frameworks' => \local_miwlab\local\competencies::frameworks()];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworks' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Framework id'),
                'idnumber' => new external_value(PARAM_RAW, 'ID number (unique in the site)'),
                'shortname' => new external_value(PARAM_RAW, 'Name'),
                'description' => new external_value(PARAM_RAW, 'Description (HTML)'),
                'visible' => new external_value(PARAM_BOOL, 'Visible'),
                'categoryid' => new external_value(PARAM_INT, 'Course category it belongs to; 0: the whole site'),
                'scaleid' => new external_value(PARAM_INT, 'Scale id'),
                'scalename' => new external_value(PARAM_RAW, 'Scale name'),
                'scaleitems' => new external_multiple_structure(new external_value(PARAM_RAW, 'Item'), 'Scale items, lowest first'),
                'scaleconfiguration' => new external_value(PARAM_RAW, 'Which items are the default and the proficient ones (JSON)'),
                'competencycount' => new external_value(PARAM_INT, 'How many competencies it has'),
            ])),
        ]);
    }
}
