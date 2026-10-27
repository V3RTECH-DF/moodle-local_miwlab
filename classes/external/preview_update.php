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

use local_miwlab\local\replacer;
use local_miwlab\local\restorer;
use local_miwlab\local\updater;

/**
 * The preview of the safe update of a course (1.9.0, issue 204): each operation the MiwLab asks, sorted against the course as it
 * is now and the progress of each activity: updated in place, added or hidden, or left out (with the reason). Changes nothing in
 * the course. Counts only about people.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class preview_update extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course to update'),
            'operations' => new external_value(PARAM_RAW, 'The operations, as a JSON object'),
            'expectedsignature' => new external_value(
                PARAM_ALPHANUM,
                'The state of the course the MiwLab knows (local_miwlab_get_course_state): refused if it changed',
                VALUE_DEFAULT,
                ''
            ),
            'draftitemid' => new external_value(
                PARAM_INT,
                'Draft area where the package with ONLY the new activities and sections was uploaded; 0 when there are none',
                VALUE_DEFAULT,
                0
            ),
            'filename' => new external_value(
                PARAM_FILE,
                'Name of the package in the draft area; empty: the only file there',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Makes the preview.
     *
     * @param int $courseid
     * @param string $operations
     * @param string $expectedsignature
     * @param int $draftitemid
     * @param string $filename
     * @return array
     */
    public static function execute(
        int $courseid,
        string $operations,
        string $expectedsignature = '',
        int $draftitemid = 0,
        string $filename = ''
    ): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'operations' => $operations,
            'expectedsignature' => $expectedsignature,
            'draftitemid' => $draftitemid,
            'filename' => $filename,
        ]);
        $course = replacer::course($params['courseid']);
        self::validate_category_context((int) $course->category);
        $context = \context_course::instance($course->id);
        updater::require_capabilities($context, $course);
        $package = $params['draftitemid'] > 0 ? restorer::draft_file($params['draftitemid'], $params['filename']) : null;
        return updater::preview($course, $params['operations'], $params['expectedsignature'], $package);
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'previewid' => new external_value(PARAM_ALPHANUM, 'Identifier of the preview; apply it with local_miwlab_apply_update'),
            'courseid' => new external_value(PARAM_INT, 'The course'),
            'signature' => new external_value(PARAM_ALPHANUM, 'State of the course content when previewed'),
            'expires' => new external_value(PARAM_INT, 'Until when the preview can be applied (Unix time)'),
            'enrolled' => new external_value(PARAM_INT, 'People enrolled, the MiwLab account aside'),
            'withprogress' => new external_value(PARAM_INT, 'People with progress in the course (counts only)'),
            'unknownmodules' => new external_multiple_structure(
                new external_value(PARAM_PLUGIN, 'Module of an activity whose progress the plugin cannot read')
            ),
            'counts' => new external_single_structure([
                'update' => new external_value(PARAM_INT, 'Items updated in place'),
                'add' => new external_value(PARAM_INT, 'Items added or hidden'),
                'skip' => new external_value(PARAM_INT, 'Items left out'),
                'choice' => new external_value(PARAM_INT, 'Course-wide items the person chooses'),
            ]),
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_RAW, 'Stable identifier of the item'),
                'group' => new external_value(PARAM_ALPHA, 'update, add, skip or choice'),
                'kind' => new external_value(
                    PARAM_ALPHA,
                    'activity, added, retired, section, newsection, retiredsection, order or coursesetting'
                ),
                'ref' => new external_value(PARAM_RAW, 'The activity or section id, or the key the MiwLab gave'),
                'module' => new external_value(PARAM_RAW, 'Module (or block) name'),
                'name' => new external_value(PARAM_RAW, 'Name of the activity or section'),
                'section' => new external_value(PARAM_RAW, 'Name of the section where it is'),
                'field' => new external_value(
                    PARAM_RAW,
                    'What changes: name, text, completion, availability, visible, dates, grademax...'
                ),
                'action' => new external_value(PARAM_ALPHA, 'update, add, hide, keep or skip'),
                'reason' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Why it is left out (progress, unknowntype, newfile, persondata...)'
                ),
                'people' => new external_value(
                    PARAM_INT,
                    'People with progress in it (counts only); for a course-wide item, who is affected'
                ),
                'kept' => new external_value(PARAM_INT, 'People whose value is kept (a total typed by hand)'),
                'warnings' => new external_multiple_structure(new external_value(
                    PARAM_ALPHANUMEXT,
                    'A warning that does not block'
                )),
                'suggestdelete' => new external_value(PARAM_BOOL, 'Hidden and nobody has data in it: it could be deleted by hand'),
                'choice' => new external_value(
                    PARAM_ALPHANUMEXT,
                    'Course-wide item: the choice of this preview (keep, replace or apply)'
                ),
                'canchoose' => new external_value(PARAM_BOOL, 'Course-wide item: replacing or removing it is possible'),
                'needsdiscard' => new external_value(PARAM_BOOL, 'Replacing it makes somebody lose the completion of the course'),
            ])),
        ]);
    }
}
