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

use stdClass;

/**
 * A quiz as it is in Moodle, for the questions of the MiwLab (1.15.0, issue 349): its slots (a question, or a draw from a
 * category) and the entries of its own bank and of its slots. It only reads.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_bank {
    /** Seconds of margin around the dates of a quiz, for who is starting it right then. */
    const START_MARGIN = 60;

    /** @var stdClass The quiz record. */
    public $quiz;
    /** @var \context_module The context of the quiz. */
    public $context;
    /** @var stdClass[] The slots in order: entryid, pinned, and set (category, contextid, simple) for a draw. */
    public $slots = [];
    /** @var stdClass[] The entries of the bank of the quiz and of its slots, by id, each with its versions, the latest first. */
    public $entries = [];

    /**
     * Reads a quiz.
     *
     * @param stdClass|\cm_info $cm The course module of the quiz.
     */
    public function __construct($cm) {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        $this->quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->quiz->cmid = (int) $cm->id;
        $this->context = \context_module::instance($cm->id);
        $this->read_slots();
        $this->read_entries();
    }

    /**
     * The slots, each with what it holds.
     */
    protected function read_slots(): void {
        global $DB;
        $where = ['component' => 'mod_quiz', 'questionarea' => 'slot', 'usingcontextid' => $this->context->id];
        $references = [];
        foreach ($DB->get_records('question_references', $where) as $reference) {
            $references[(int) $reference->itemid] = $reference;
        }
        $sets = [];
        foreach ($DB->get_records('question_set_references', $where) as $set) {
            $sets[(int) $set->itemid] = $set;
        }
        foreach ($DB->get_records('quiz_slots', ['quizid' => $this->quiz->id], 'slot') as $slot) {
            $reference = $references[(int) $slot->id] ?? null;
            $slot->entryid = $reference ? (int) $reference->questionbankentryid : 0;
            $slot->pinned = $reference && $reference->version !== null;
            $slot->set = isset($sets[(int) $slot->id]) ? self::set_of($sets[(int) $slot->id]) : null;
            $this->slots[] = $slot;
        }
    }

    /**
     * What a draw draws from. It is simple when it is one category, without subcategories and without any other filter.
     *
     * @param stdClass $reference The question_set_references record.
     * @return stdClass category, contextid and simple.
     */
    protected static function set_of(stdClass $reference): stdClass {
        $condition = json_decode((string) $reference->filtercondition, true);
        $set = (object) ['category' => 0, 'contextid' => (int) $reference->questionscontextid, 'simple' => false];
        if (!is_array($condition)) {
            return $set;
        }
        if (isset($condition['questioncategoryid'])) {
            // Moodle up to 4.2.
            $set->category = (int) $condition['questioncategoryid'];
            $set->simple = empty($condition['includingsubcategories']) && empty($condition['tags']);
            return $set;
        }
        $filters = $condition['filter'] ?? [];
        $category = $filters['category'] ?? [];
        $values = array_values($category['values'] ?? []);
        $set->category = (int) ($values[0] ?? 0);
        $subcategories = $category['filteroptions']['includesubcategories'] ?? $category['includesubcategories'] ?? false;
        $simple = count($values) === 1 && empty($subcategories) && (int) ($category['jointype'] ?? 1) !== 0;
        foreach ($filters as $name => $filter) {
            // The filter of hidden questions says nothing here: a hidden question is never drawn.
            if ($name !== 'category' && $name !== 'hidden' && !empty(array_filter((array) ($filter['values'] ?? [])))) {
                $simple = false;
            }
        }
        $set->simple = $simple;
        return $set;
    }

    /**
     * The entries in the slots and in the bank of the quiz, with their versions. A draw does not open the bank it draws from:
     * that can be the bank of another course or of the site, and as big as the site.
     */
    protected function read_entries(): void {
        global $DB;
        $inslots = [];
        foreach ($this->slots as $slot) {
            if ($slot->entryid) {
                $inslots[$slot->entryid] = $slot->entryid;
            }
        }
        $where = ['qc.contextid = :context'];
        $params = ['context' => $this->context->id];
        if ($inslots) {
            [$sql, $found] = $DB->get_in_or_equal($inslots, SQL_PARAMS_NAMED, 'qq');
            $where[] = "qbe.id $sql";
            $params += $found;
        }
        $sql = "SELECT qv.id, qv.questionbankentryid AS entryid, qv.version, qv.status, qbe.idnumber,
                       qbe.questioncategoryid AS category, qc.contextid, q.id AS questionid, q.qtype, q.questiontext, q.stamp
                  FROM {question_versions} qv
                  JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                  JOIN {question_categories} qc ON qc.id = qbe.questioncategoryid
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE q.parent = 0 AND (" . implode(' OR ', $where) . ")
              ORDER BY qv.questionbankentryid, qv.version DESC";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            $id = (int) $row->entryid;
            if (!isset($this->entries[$id])) {
                $this->entries[$id] = (object) ['id' => $id, 'category' => (int) $row->category,
                    'contextid' => (int) $row->contextid, 'idnumber' => (string) $row->idnumber, 'versions' => []];
            }
            $this->entries[$id]->versions[] = $row;
        }
    }

    /**
     * The entries a category has that a draw can take.
     *
     * @param int $category
     * @return int[] The ids of the entries.
     */
    public function drawable_in(int $category): array {
        $ids = [];
        foreach ($this->entries as $entry) {
            if ($entry->category === $category && self::drawable($entry)) {
                $ids[] = $entry->id;
            }
        }
        sort($ids);
        return $ids;
    }

    /**
     * Whether Moodle can draw an entry: a version ready, of a type that takes part in a draw.
     *
     * @param stdClass $entry
     * @return bool
     */
    public static function drawable(stdClass $entry): bool {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        foreach ($entry->versions as $version) {
            if ($version->status === 'ready') {
                return \question_bank::is_qtype_installed($version->qtype)
                    && \question_bank::get_qtype($version->qtype)->is_usable_by_random();
            }
        }
        return false;
    }

    /**
     * Whether an entry is a Description: text between the questions, which is never drawn and stays where it is.
     *
     * @param stdClass $entry
     * @return bool
     */
    public static function is_description(stdClass $entry): bool {
        $latest = reset($entry->versions);
        return $latest && $latest->qtype === 'description';
    }

    /**
     * The question of the version a draw takes: the latest that is ready.
     *
     * @param stdClass $entry
     * @return int The question id, 0 without one.
     */
    public static function ready_question(stdClass $entry): int {
        foreach ($entry->versions as $version) {
            if ($version->status === 'ready') {
                return (int) $version->questionid;
            }
        }
        return 0;
    }

    /**
     * Whether a student can start the quiz now: it is shown, in a course that is shown, inside its dates or inside the dates
     * of an override. Nothing else that may restrict it is trusted. A minute of margin on each side of the dates.
     *
     * @param int $cmid
     * @return bool
     */
    public static function can_be_started(int $cmid): bool {
        global $DB;
        $quiz = $DB->get_record_sql(
            'SELECT q.id, q.timeopen, q.timeclose, cm.visible, c.visible AS coursevisible
               FROM {course_modules} cm
               JOIN {course} c ON c.id = cm.course
               JOIN {quiz} q ON q.id = cm.instance
              WHERE cm.id = :cmid',
            ['cmid' => $cmid],
            MUST_EXIST
        );
        if (!$quiz->visible || !$quiz->coursevisible) {
            return false;
        }
        $now = time();
        $inside = function ($open, $close) use ($now): bool {
            return (!(int) $open || (int) $open <= $now + self::START_MARGIN)
                && (!(int) $close || (int) $close > $now - self::START_MARGIN);
        };
        if ($inside($quiz->timeopen, $quiz->timeclose)) {
            return true;
        }
        foreach ($DB->get_records('quiz_overrides', ['quiz' => $quiz->id], '', 'id, timeopen, timeclose') as $override) {
            if ($inside($override->timeopen ?? $quiz->timeopen, $override->timeclose ?? $quiz->timeclose)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether another quiz, or anything else of Moodle, uses an entry or draws from its category.
     *
     * @param stdClass $entry
     * @return bool
     */
    public function used_elsewhere(stdClass $entry): bool {
        global $DB;
        $other = 'usingcontextid <> :using';
        $params = ['using' => $this->context->id];
        if (
            $DB->record_exists_select(
                'question_references',
                $other . ' AND questionbankentryid = :entry',
                $params + ['entry' => $entry->id]
            )
        ) {
            return true;
        }
        return $DB->record_exists_select(
            'question_set_references',
            $other . ' AND questionscontextid = :context',
            $params + ['context' => $entry->contextid]
        );
    }

    /**
     * How many attempts of people the quiz has (the previews of the team are not attempts).
     *
     * @return int People with an attempt.
     */
    public function people_with_attempts(): int {
        global $DB;
        return (int) $DB->count_records_sql(
            'SELECT COUNT(DISTINCT userid) FROM {quiz_attempts} WHERE quiz = :quiz AND preview = 0',
            ['quiz' => $this->quiz->id]
        );
    }
}
