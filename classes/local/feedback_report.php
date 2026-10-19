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

use moodle_exception;
use stdClass;

/**
 * Feedback activities of a course and the consolidated results of an anonymous one, as Moodle consolidates them.
 *
 * The answers of a feedback that is not anonymous are never read. Open answers (text) leave only when asked,
 * in alphabetical order (not in the order they arrived).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class feedback_report {
    /** @var string[] Item types whose answers are free text. */
    const TEXT_TYPES = ['textarea', 'textfield'];

    /** @var string[] Item types whose analysis Moodle already consolidates in counts per option. */
    const COUNTED_TYPES = ['multichoice', 'multichoicerated'];

    /**
     * The feedback activities of a course.
     *
     * @param stdClass $course
     * @return array
     */
    public static function list(stdClass $course): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        $modinfo = get_fast_modinfo($course);
        $result = [];
        foreach ($modinfo->get_instances_of('feedback') as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $feedback = $DB->get_record('feedback', ['id' => $cm->instance], 'id, name, anonymous', MUST_EXIST);
            $items = $DB->get_records('feedback_item', ['feedback' => $feedback->id], 'position, id');
            $result[] = [
                'id' => (int) $feedback->id,
                'cmid' => (int) $cm->id,
                'sectionnumber' => (int) $cm->sectionnum,
                'name' => $cm->get_formatted_name(),
                'anonymous' => (int) $feedback->anonymous === FEEDBACK_ANONYMOUS_YES,
                'visible' => (bool) $cm->visible,
                'items' => array_values(array_map([self::class, 'export_item'], $items)),
            ];
        }
        return $result;
    }

    /**
     * The item as the web service returns it.
     *
     * @param stdClass $item
     * @return array
     */
    public static function export_item(stdClass $item): array {
        return [
            'id' => (int) $item->id,
            'typ' => (string) $item->typ,
            'name' => (string) $item->name,
            'label' => (string) $item->label,
            'presentation' => (string) $item->presentation,
            'position' => (int) $item->position,
            'hasvalue' => (bool) $item->hasvalue,
            'required' => (bool) $item->required,
        ];
    }

    /**
     * The consolidated results of an anonymous feedback, over every participant (no group).
     *
     * @param stdClass $feedback
     * @param \cm_info $cm
     * @param stdClass $course
     * @param bool $includetext Whether the open answers leave.
     * @return array
     * @throws moodle_exception When the feedback is not anonymous.
     */
    public static function analysis(stdClass $feedback, \cm_info $cm, stdClass $course, bool $includetext): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/feedback/lib.php');
        if ((int) $feedback->anonymous !== FEEDBACK_ANONYMOUS_YES) {
            throw new moodle_exception('error:notanonymous', 'local_miwlab');
        }
        $structure = new \mod_feedback_structure($feedback, $cm, $course->id);
        $itemsdata = [];
        foreach ($structure->get_items(true) as $item) {
            unset($item->itemnr);
            $withheld = false;
            $data = [];
            $answercount = 0;
            $itemobj = feedback_get_item_class($item->typ);
            if (in_array($item->typ, self::COUNTED_TYPES, true)) {
                $data = array_values($itemobj->get_analysed_for_external($item, 0));
            } else if (in_array($item->typ, self::TEXT_TYPES, true)) {
                $texts = array_values(array_filter(
                    array_map('strval', $itemobj->get_analysed_for_external($item, 0)),
                    function (string $text): bool {
                        return trim($text) !== '';
                    }
                ));
                // How many answered is a count, and leaves either way; the text only when asked.
                $answercount = count($texts);
                if ($includetext) {
                    \core_collator::asort($texts, \core_collator::SORT_STRING);
                    $data = array_values($texts);
                } else {
                    $withheld = true;
                }
            } else if ($item->typ === 'numeric') {
                // Moodle lists each value; only the count and the average leave.
                $values = array_map('floatval', $itemobj->get_analysed_for_external($item, 0));
                $answercount = count($values);
                $data[] = json_encode([
                    'answercount' => count($values),
                    'average' => $values ? array_sum($values) / count($values) : null,
                ]);
            } else {
                // Other types (info, captcha...) carry nothing to consolidate, or data about the response itself.
                $withheld = true;
            }
            $itemsdata[] = [
                'item' => self::export_item($item),
                'data' => $data,
                'withheld' => $withheld,
                'answercount' => $answercount,
            ];
        }
        return [
            'feedbackid' => (int) $feedback->id,
            'cmid' => (int) $cm->id,
            'courseid' => (int) $course->id,
            'name' => $cm->get_formatted_name(),
            'anonymous' => true,
            'completedcount' => (int) $structure->count_completed_responses(0),
            'itemscount' => count($itemsdata),
            'textincluded' => $includetext,
            'itemsdata' => $itemsdata,
        ];
    }
}
