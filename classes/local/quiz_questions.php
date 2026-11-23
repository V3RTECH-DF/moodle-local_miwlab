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
 * Decides what the questions of the MiwLab change in a quiz that is in Moodle (1.15.0, issue 349). It changes nothing: the same
 * decision makes the state the MiwLab reads, the preview and the check right before applying.
 *
 * The rules: each question goes once (recognised by its stamp, by the record of what went and, when it came in a package without
 * a stamp, by its content); a quiz with attempts, or that a student can start now, is never touched; nothing is deleted from
 * the question bank; without a draw the questions are added to the ones there; with a draw they and the ones made in Moodle that
 * are in the quiz are one set: a question of another bank or of another use is copied into it, with the original intact, and a
 * Description stays fixed. A draw made in Moodle over another set keeps the quiz out, with the reason.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_questions {
    /** The table of what went to each quiz. */
    const TABLE = 'local_miwlab_questions';

    /** The start of the ID number of the category the plugin makes for a set. */
    const SET_CATEGORY = 'miwlab:set';

    /** The start of the stamp of a question that came in a package of the MiwLab. */
    const PACKAGE_STAMP = 'miwlab_q_';

    /** @var string[] Needed in the quiz to add questions. */
    const CAPABILITIES = [
        'mod/quiz:manage',
        'moodle/question:add',
        'moodle/question:managecategory',
        'moodle/question:useall',
    ];

    /** @var array What every answer tells about a quiz, with the values of "nothing". */
    const DETAILS = ['add' => 0, 'delivered' => 0, 'joined' => 0, 'copied' => 0, 'kept' => 0, 'draw' => 0, 'pool' => 0,
        'slotsbefore' => 0, 'slotsafter' => 0, 'marksbefore' => 0.0, 'marksafter' => 0.0, 'grade' => 0.0];

    /**
     * The decision for a quiz.
     *
     * @param stdClass|\cm_info|null $cm The course module, null when it is not there.
     * @param array $op One quiz of question_list::parse().
     * @return stdClass action (none, update or skip), reason, people, warnings, details, state, and what the writer needs: bank,
     *   new (questions), delivered (key => entry), join (entries), copy (entry => question), kept (slots), pool (category, 0 a
     *   new one) and rebuild.
     */
    public static function decide($cm, array $op): stdClass {
        $decision = (object) ['action' => 'none', 'reason' => '', 'people' => 0, 'warnings' => [], 'details' => self::DETAILS,
            'state' => '', 'bank' => null, 'new' => [], 'delivered' => [], 'join' => [],
            'copy' => [], 'kept' => [], 'pool' => 0, 'rebuild' => false];
        if (!$cm || !empty($cm->deletioninprogress)) {
            return self::leave_out($decision, 'deleting');
        }
        if ($cm->modname !== 'quiz') {
            return self::leave_out($decision, 'unknowntype');
        }
        $bank = new quiz_bank($cm);
        $decision->bank = $bank;
        $decision->delivered = self::delivered($bank, $op['items']);
        foreach ($op['items'] as $question) {
            if (!array_key_exists($question['key'], $decision->delivered)) {
                $decision->new[] = $question;
            }
        }
        $details = &$decision->details;
        $details['add'] = count($decision->new);
        $details['delivered'] = count($decision->delivered);
        $details['draw'] = $op['draw'];
        $details['slotsbefore'] = count($bank->slots);
        $details['slotsafter'] = count($bank->slots) + count($decision->new);
        foreach ($bank->slots as $slot) {
            $details['marksbefore'] += (float) $slot->maxmark;
        }
        $details['marksafter'] = $details['marksbefore'] + count($decision->new);
        $details['grade'] = (float) $bank->quiz->grade;
        unset($details);

        $obstacle = '';
        $changes = count($decision->new) > 0;
        if ($op['draw'] > 0) {
            $obstacle = self::one_set($decision, $op['draw']);
            $changes = $changes || $decision->rebuild;
        }
        if (!$changes) {
            return $decision;
        }
        $decision->state = self::state($decision);
        $attempted = $bank->people_with_attempts();
        if ($attempted > 0) {
            $decision->people = $attempted;
            return self::leave_out($decision, 'attempts');
        }
        $needed = self::CAPABILITIES;
        if ($decision->join) {
            $needed[] = 'moodle/question:moveall';
        }
        if (!has_all_capabilities($needed, $bank->context)) {
            return self::leave_out($decision, 'nopermission');
        }
        if ($obstacle !== '') {
            return self::leave_out($decision, $obstacle);
        }
        // Last, as it is what the team can undo: hide the quiz and synchronise again.
        if (quiz_bank::can_be_started((int) $bank->quiz->cmid)) {
            return self::leave_out($decision, 'canstart');
        }
        $decision->action = 'update';
        $decision->warnings = self::warnings($decision);
        return $decision;
    }

    /**
     * Leaves a quiz out, with the reason.
     *
     * @param stdClass $decision
     * @param string $reason
     * @return stdClass
     */
    protected static function leave_out(stdClass $decision, string $reason): stdClass {
        $decision->action = 'skip';
        $decision->reason = $reason;
        return $decision;
    }

    /**
     * The questions that already went: by the record of the plugin, by the stamp and, without a stamp, by the content.
     *
     * @param quiz_bank $bank
     * @param array $questions
     * @return array key => the entry (0 when it is not in the bank any more).
     */
    protected static function delivered(quiz_bank $bank, array $questions): array {
        global $DB;
        $recorded = $DB->get_records_menu(self::TABLE, ['cmid' => $bank->quiz->cmid], '', 'questionkey, entryid');
        $bystamp = [];
        $unstamped = [];
        foreach ($bank->entries as $entry) {
            if (strpos($entry->idnumber, question_list::STAMP) === 0) {
                $bystamp[substr($entry->idnumber, strlen(question_list::STAMP))] = $entry->id;
            } else {
                $unstamped[$entry->id] = $entry;
            }
        }
        $delivered = [];
        $open = [];
        foreach ($questions as $question) {
            $key = $question['key'];
            if (array_key_exists($key, $recorded)) {
                $delivered[$key] = (int) $recorded[$key];
                unset($unstamped[(int) $recorded[$key]]);
            } else if (isset($bystamp[$key])) {
                $delivered[$key] = $bystamp[$key];
            } else {
                $open[] = $question;
            }
        }
        // The same question, answers included. Then, only for what a package of the MiwLab brought, the text it had: the
        // answers or the text may have been edited since. Each version is read once, whatever the number of questions.
        $sametext = [];
        $frompackage = [];
        foreach ($unstamped as $entry) {
            $packaged = self::came_in_package($entry);
            foreach ($entry->versions as $version) {
                $words = question_list::plain((string) $version->questiontext);
                if ($words === '' || !in_array($version->qtype, question_list::TYPES, true)) {
                    continue;
                }
                $sametext[$version->qtype . ':' . $words][] = [$entry->id, $version];
                if ($packaged) {
                    $frompackage[$version->qtype . ':' . $words][] = $entry->id;
                }
            }
        }
        $left = [];
        $there = [];
        foreach ($open as $question) {
            $candidates = $sametext[$question['type'] . ':' . question_list::plain($question['text'])] ?? [];
            $asked = $candidates ? self::answers_asked($question) : [];
            foreach ($candidates as [$id, $version]) {
                if (!isset($unstamped[$id])) {
                    continue;
                }
                if (!isset($there[$version->questionid])) {
                    $there[$version->questionid] = self::answers_there($version);
                }
                if ($there[$version->questionid] === $asked) {
                    $delivered[$question['key']] = $id;
                    unset($unstamped[$id]);
                    continue 2;
                }
            }
            $left[] = $question;
        }
        if (!$frompackage) {
            return $delivered;
        }
        foreach ($left as $question) {
            foreach (array_merge([$question['text']], $question['known']) as $text) {
                foreach ($frompackage[$question['type'] . ':' . question_list::plain($text)] ?? [] as $id) {
                    if (isset($unstamped[$id])) {
                        $delivered[$question['key']] = $id;
                        unset($unstamped[$id]);
                        continue 3;
                    }
                }
            }
        }
        return $delivered;
    }

    /**
     * Whether an entry came in a package of the MiwLab.
     *
     * @param stdClass $entry
     * @return bool
     */
    protected static function came_in_package(stdClass $entry): bool {
        foreach ($entry->versions as $version) {
            if (strpos((string) $version->stamp, self::PACKAGE_STAMP) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The answers of a question of the list, to compare.
     *
     * @param array $question
     * @return string[]
     */
    protected static function answers_asked(array $question): array {
        $list = [];
        foreach ($question['answers'] as $position => $answer) {
            if ($question['type'] === 'truefalse') {
                $list[] = ($position === 0 ? 'true' : 'false') . ($answer['correct'] ? '+' : '-');
            } else if ($question['type'] === 'match') {
                $list[] = question_list::plain($answer['text']) . '=' . $answer['match'];
            } else if ($question['type'] === 'shortanswer') {
                $list[] = trim($answer['text']) . ($answer['correct'] ? '+' : '-');
            } else {
                $list[] = question_list::plain($answer['text']) . ($answer['correct'] ? '+' : '-');
            }
        }
        sort($list);
        return $list;
    }

    /**
     * The answers of a version in the bank, to compare.
     *
     * @param stdClass $version
     * @return string[]
     */
    protected static function answers_there(stdClass $version): array {
        global $DB;
        $list = [];
        if ($version->qtype === 'match') {
            foreach ($DB->get_records('qtype_match_subquestions', ['questionid' => $version->questionid]) as $pair) {
                $list[] = question_list::plain((string) $pair->questiontext) . '=' . $pair->answertext;
            }
        } else {
            $answers = $DB->get_records('question_answers', ['question' => $version->questionid], 'id');
            $options = $version->qtype === 'truefalse'
                ? $DB->get_record('question_truefalse', ['question' => $version->questionid]) : null;
            foreach ($answers as $answer) {
                $right = (float) $answer->fraction > 0 ? '+' : '-';
                if ($options) {
                    $list[] = ((int) $answer->id === (int) $options->trueanswer ? 'true' : 'false') . $right;
                } else if ($version->qtype === 'shortanswer') {
                    $list[] = trim((string) $answer->answer) . $right;
                } else {
                    $list[] = question_list::plain((string) $answer->answer) . $right;
                }
            }
        }
        sort($list);
        return $list;
    }

    /**
     * Works out the one set of a quiz with a draw: the questions of the MiwLab and the ones made in Moodle that are in the quiz.
     *
     * @param stdClass $decision
     * @param int $draw
     * @return string Why it cannot be one set, '' when it can.
     */
    protected static function one_set(stdClass $decision, int $draw): string {
        $bank = $decision->bank;
        $fixed = [];
        $draws = [];
        $keptmarks = 0.0;
        foreach ($bank->slots as $slot) {
            $entry = $bank->entries[$slot->entryid] ?? null;
            if ($slot->set) {
                $draws[] = $slot;
            } else if ($entry && quiz_bank::is_description($entry)) {
                // A Description stays fixed, where it is.
                $decision->kept[] = (int) $slot->id;
                $keptmarks += (float) $slot->maxmark;
            } else {
                $fixed[] = $slot;
            }
        }
        $decision->rebuild = $fixed || count($draws) !== $draw;
        $obstacle = '';
        $pool = [];
        if ($draws) {
            $first = $draws[0]->set;
            foreach ($draws as $slot) {
                if (!$slot->set->simple || $slot->set->category !== $first->category) {
                    $obstacle = 'moodledraw';
                }
            }
            if ($obstacle === '' && self::is_own_set($decision, $first)) {
                $decision->pool = $first->category;
                $pool = array_fill_keys($bank->drawable_in($first->category), true);
            } else {
                $obstacle = 'moodledraw';
            }
        }
        $ours = array_flip(array_filter($decision->delivered));
        $joined = 0;
        foreach ($fixed as $slot) {
            $entry = $bank->entries[$slot->entryid] ?? null;
            if (!$entry || $slot->pinned || !quiz_bank::drawable($entry) || !empty($slot->quizgradeitemid)) {
                $obstacle = $obstacle ?: 'notdrawable';
                continue;
            }
            if (isset($pool[$entry->id])) {
                continue;
            }
            // The one place that decides what happens to a question that is not only of this quiz: it is copied into the set
            // and the original stays as it is, where it is.
            if ($entry->contextid !== (int) $bank->context->id || $bank->used_elsewhere($entry)) {
                if (!has_capability('moodle/question:useall', \context::instance_by_id($entry->contextid))) {
                    $obstacle = $obstacle ?: 'nopermission';
                }
                $decision->copy[$entry->id] = quiz_bank::ready_question($entry);
            } else {
                $decision->join[] = $entry->id;
            }
            $pool[$entry->id] = true;
            if (!isset($ours[$entry->id]) && strpos($entry->idnumber, question_list::STAMP) !== 0) {
                $joined++;
            }
        }
        $size = count($pool) + count($decision->new);
        $kept = count($decision->kept);
        $decision->details['joined'] = $joined;
        $decision->details['copied'] = count($decision->copy);
        $decision->details['kept'] = $kept;
        $decision->details['pool'] = $size;
        $decision->details['slotsafter'] = $decision->rebuild ? $draw + $kept : count($bank->slots);
        $decision->details['marksafter'] = $decision->rebuild ? $draw + $keptmarks : $decision->details['marksbefore'];
        return $obstacle ?: ($size < $draw ? 'toofewquestions' : '');
    }

    /**
     * Whether a draw is over a set of the MiwLab in the bank of this quiz.
     *
     * @param stdClass $decision
     * @param stdClass $set
     * @return bool
     */
    protected static function is_own_set(stdClass $decision, stdClass $set): bool {
        global $DB;
        $bank = $decision->bank;
        $category = $DB->get_record('question_categories', ['id' => $set->category]);
        if (!$category || (int) $category->contextid !== (int) $bank->context->id) {
            return false;
        }
        if (strpos((string) $category->idnumber, self::SET_CATEGORY) === 0) {
            return true;
        }
        foreach ($bank->entries as $entry) {
            if ($entry->category !== (int) $category->id) {
                continue;
            }
            // By the mark only: a question of the team that looks like one of the MiwLab does not make the category ours.
            if (strpos($entry->idnumber, question_list::STAMP) === 0 || self::came_in_package($entry)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What the team should know before it is applied.
     *
     * @param stdClass $decision
     * @return string[]
     */
    protected static function warnings(stdClass $decision): array {
        global $DB;
        $bank = $decision->bank;
        $warnings = [];
        if ($decision->rebuild && $bank->slots) {
            $weights = false;
            $layout = $DB->count_records('quiz_sections', ['quizid' => $bank->quiz->id]) > 1;
            foreach ($bank->slots as $slot) {
                $weights = $weights || abs((float) $slot->maxmark - 1) > 0.000001;
                $layout = $layout || (int) $slot->page > 1 || !empty($slot->requireprevious);
            }
            if ($weights) {
                $warnings[] = 'questionweights';
            }
            if ($layout) {
                $warnings[] = 'questionlayout';
            }
        }
        if ($DB->record_exists('quiz_attempts', ['quiz' => $bank->quiz->id, 'preview' => 1])) {
            $warnings[] = 'previewsdeleted';
        }
        return $warnings;
    }

    /**
     * What the decision was made on: if it is another when applying, the quiz is left out.
     *
     * @param stdClass $decision
     * @return string
     */
    protected static function state(stdClass $decision): string {
        global $DB;
        $bank = $decision->bank;
        $sections = [];
        $records = $DB->get_records('quiz_sections', ['quizid' => $bank->quiz->id], 'firstslot, id');
        foreach ($records as $section) {
            $sections[] = [(int) $section->firstslot, (string) $section->heading, (int) $section->shufflequestions];
        }
        $slots = [];
        foreach ($bank->slots as $slot) {
            $slots[] = [(int) $slot->id, (int) $slot->slot, $slot->entryid, $slot->set ? $slot->set->category : 0,
                (float) $slot->maxmark, (int) $slot->page, (int) ($slot->requireprevious ?? 0)];
        }
        return sha1(json_encode([$slots, $sections, $decision->join, $decision->copy, $decision->pool,
            array_column($decision->new, 'key'),
            $decision->pool ? $bank->drawable_in($decision->pool) : [], (float) $bank->quiz->grade]));
    }
}
