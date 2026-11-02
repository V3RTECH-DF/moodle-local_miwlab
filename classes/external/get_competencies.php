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
 * The competencies of a framework, with the rule of each (1.10.0, issue 252). Reads only.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_competencies extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'frameworkid' => new external_value(PARAM_INT, 'Framework id'),
        ]);
    }

    /**
     * Returns the competencies of the framework.
     *
     * @param int $frameworkid
     * @return array
     */
    public static function execute(int $frameworkid): array {
        $params = self::validate_parameters(self::execute_parameters(), ['frameworkid' => $frameworkid]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/miwlab:viewcompetencies', $context);
        return ['competencies' => \local_miwlab\local\competencies::competencies_of($params['frameworkid'])];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'competencies' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Competency id'),
                'idnumber' => new external_value(PARAM_RAW, 'ID number (unique in the framework)'),
                'shortname' => new external_value(PARAM_RAW, 'Name'),
                'description' => new external_value(PARAM_RAW, 'Description (HTML)'),
                'parentid' => new external_value(PARAM_INT, 'Parent competency id; 0: first level'),
                'parentidnumber' => new external_value(PARAM_RAW, 'ID number of the parent; empty: first level'),
                'sortorder' => new external_value(PARAM_INT, 'Order among its siblings'),
                'ruletype' => new external_value(PARAM_RAW, 'Class of the rule of this competency; empty: none'),
                'ruleoutcome' => new external_value(PARAM_ALPHA, 'What the rule does: none, evidence, complete or recommend'),
                'scaleid' => new external_value(PARAM_INT, 'Own scale id; 0: the one of the framework'),
            ])),
        ]);
    }
}
