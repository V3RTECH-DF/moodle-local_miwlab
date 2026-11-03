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
 * Completes a competency framework of Moodle with the competencies the MiwLab has (1.10.0, issue 252).
 *
 * Creates what is missing and updates the text; never deletes, moves or re-scales.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class complete_competency_framework extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'framework' => new external_single_structure([
                'idnumber' => new external_value(PARAM_RAW_TRIMMED, 'ID number of the framework (found by it, or created with it)'),
                'shortname' => new external_value(PARAM_RAW_TRIMMED, 'Name; needed to create', VALUE_DEFAULT, ''),
                'description' => new external_value(
                    PARAM_RAW,
                    'Description (HTML); empty keeps the one there is',
                    VALUE_DEFAULT,
                    ''
                ),
                'scaleid' => new external_value(PARAM_INT, 'Scale of a new framework; 0: the default of Moodle', VALUE_DEFAULT, 0),
                'categoryid' => new external_value(PARAM_INT, 'Category of a new framework; 0: the whole site', VALUE_DEFAULT, 0),
            ]),
            'competencies' => new external_multiple_structure(new external_single_structure([
                'idnumber' => new external_value(PARAM_RAW_TRIMMED, 'ID number in the framework'),
                'shortname' => new external_value(PARAM_RAW_TRIMMED, 'Name; needed to create', VALUE_DEFAULT, ''),
                'description' => new external_value(
                    PARAM_RAW,
                    'Description (HTML); empty keeps the one there is',
                    VALUE_DEFAULT,
                    ''
                ),
                'parentidnumber' => new external_value(
                    PARAM_RAW_TRIMMED,
                    'ID number of the parent; empty: first level',
                    VALUE_DEFAULT,
                    ''
                ),
                'rule' => new external_value(
                    PARAM_ALPHA,
                    'For a competency with daughters: all (achieved when all the children are; the default) or any (achieved when '
                        . 'any child is)',
                    VALUE_DEFAULT,
                    'all'
                ),
            ])),
            'parentrule' => new external_value(
                PARAM_BOOL,
                'A competency with daughters in the list and no rule gets "achieved when all the children are"',
                VALUE_DEFAULT,
                1
            ),
            'moveparents' => new external_value(
                PARAM_BOOL,
                'A competency whose ID number starts with miwlab: that sits under another one of the MiwLab is moved under the '
                    . 'parent the list says (1.10.2); nothing of the organisation is moved',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Completes the framework.
     *
     * @param array $framework
     * @param array $competencies
     * @param bool $parentrule
     * @param bool $moveparents
     * @return array
     */
    public static function execute(
        array $framework,
        array $competencies,
        bool $parentrule = true,
        bool $moveparents = false
    ): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['framework' => $framework, 'competencies' => $competencies, 'parentrule' => $parentrule,
                'moveparents' => $moveparents]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/miwlab:managecompetencies', $context);
        $result = \local_miwlab\local\competencies::complete_framework(
            $params['framework'],
            $params['competencies'],
            (bool) $params['parentrule'],
            (bool) $params['moveparents']
        );
        $count = function (string $action) use ($result): int {
            return count(array_filter($result['competencies'], fn($c) => $c['action'] === $action));
        };
        return $result + ['created' => $count('created'), 'updated' => $count('updated'), 'skipped' => $count('skipped')];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'frameworkid' => new external_value(PARAM_INT, 'Framework id'),
            'frameworkaction' => new external_value(PARAM_ALPHA, 'created, updated or unchanged'),
            'competencies' => new external_multiple_structure(new external_single_structure([
                'idnumber' => new external_value(PARAM_RAW, 'ID number'),
                'id' => new external_value(PARAM_INT, 'Competency id; 0 when skipped'),
                'action' => new external_value(PARAM_ALPHA, 'created, updated, unchanged or skipped'),
                'reason' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'invalid, duplicated, parentnotfound (skipped), parentkept (the parent differs and was not moved) or '
                        . 'parentmoved (moved under the parent asked)'
                ),
                'rule' => new external_value(
                    PARAM_ALPHA,
                    'For a mother competency: set, updated (a rule of the MiwLab replaced), kept (has another rule) or empty'
                ),
            ])),
            'created' => new external_value(PARAM_INT, 'How many competencies were created'),
            'updated' => new external_value(PARAM_INT, 'How many had the text updated'),
            'skipped' => new external_value(PARAM_INT, 'How many were skipped'),
        ]);
    }
}
