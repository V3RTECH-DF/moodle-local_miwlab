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

/**
 * The questions written in the MiwLab, as they come in the list of a safe update (1.15.0, issue 349): checked and cleaned before
 * anything is looked at. It fails closed, as the rest of the list does.
 *
 * Shape: questions[] {cmid, draw (0 none, or how many to draw), files? (the images the items cite, by digest, as in
 * update_page_files), items[] {key, type multichoice|truefalse|shortanswer|match, name,
 * text (HTML), feedback (HTML), answers[] {text, correct, feedback, match}, known[] (the texts this question had before)}}.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_list {
    /** The prefix of the ID number of a question of the MiwLab in the question bank: miwlab:q:<key>. */
    const STAMP = 'miwlab:q:';

    /** @var string[] The question types the MiwLab writes. */
    const TYPES = ['multichoice', 'truefalse', 'shortanswer', 'match'];

    /** Most quizzes in one list. */
    const MAX_QUIZZES = 500;

    /** Most questions in one quiz, and most questions drawn. */
    const MAX_QUESTIONS = 500;

    /** Most answers in one question. */
    const MAX_ANSWERS = 100;

    /** Most earlier texts of one question. */
    const MAX_KNOWN = 20;

    /** Longest HTML of a field, in bytes. */
    const MAX_HTML = 400000;

    /**
     * Reads and checks the list.
     *
     * @param mixed $list
     * @param array $cms The course modules of the course, by id.
     * @return array The list, normalised.
     * @throws moodle_exception error:invalidoperations or error:foreignid
     */
    public static function parse($list, array $cms): array {
        if (!is_array($list) || count($list) > self::MAX_QUIZZES) {
            throw self::invalid('questions is not a list');
        }
        $out = [];
        foreach (array_values($list) as $quiz) {
            self::check_fields($quiz, ['cmid', 'draw', 'items'], ['files']);
            $cmid = $quiz['cmid'];
            if (!is_int($cmid)) {
                throw self::invalid('questions activity id');
            }
            if (!isset($cms[$cmid])) {
                throw new moodle_exception('error:foreignid', 'local_miwlab');
            }
            $draw = $quiz['draw'];
            $items = $quiz['items'];
            if (
                isset($out[$cmid]) || !is_int($draw) || $draw < 0 || $draw > self::MAX_QUESTIONS
                || !is_array($items) || !$items || count($items) > self::MAX_QUESTIONS
            ) {
                throw self::invalid('questions of a quiz');
            }
            $questions = [];
            foreach ($items as $item) {
                $question = self::question($item);
                if (isset($questions[$question['key']])) {
                    throw self::invalid('question key repeated');
                }
                $questions[$question['key']] = $question;
            }
            $out[$cmid] = ['cmid' => $cmid, 'draw' => $draw, 'items' => array_values($questions)];
            if (array_key_exists('files', $quiz)) {
                $out[$cmid]['files'] = update_operations::files($quiz['files'], false, update_page_files::MAX_FILES);
            }
        }
        return array_values($out);
    }

    /**
     * One question, checked and cleaned.
     *
     * @param mixed $item
     * @return array
     */
    protected static function question($item): array {
        self::check_fields($item, ['key', 'type', 'name', 'text', 'feedback', 'answers', 'known']);
        $key = $item['key'];
        $type = $item['type'];
        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $key)) {
            throw self::invalid('question key');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw self::invalid('question type');
        }
        if (!is_array($item['answers']) || count($item['answers']) > self::MAX_ANSWERS) {
            throw self::invalid('question answers');
        }
        if (!is_array($item['known']) || count($item['known']) > self::MAX_KNOWN) {
            throw self::invalid('question known texts');
        }
        $question = [
            'key' => $key,
            'type' => $type,
            'name' => \core_text::substr(self::no_markup(clean_param(self::raw($item['name']), PARAM_TEXT)), 0, 255),
            'text' => self::shown($item['text']),
            'feedback' => self::shown($item['feedback']),
            'known' => array_values(array_map([self::class, 'html'], $item['known'])),
            'answers' => [],
        ];
        // The text of an answer is HTML where Moodle shows it as HTML; the others are plain text, which Moodle escapes, and still
        // open no tag, in case a report or a theme shows them as they are.
        $htmlanswer = in_array($type, ['multichoice', 'match'], true);
        $right = 0;
        foreach (array_values($item['answers']) as $answer) {
            self::check_fields($answer, ['text', 'correct', 'feedback', 'match']);
            if (!is_bool($answer['correct'])) {
                throw self::invalid('question answer');
            }
            $text = $htmlanswer ? self::shown($answer['text']) : trim(self::no_markup(self::raw($answer['text'])));
            $match = trim(clean_param(self::no_markup(self::raw($answer['match'])), PARAM_TEXT));
            if ($text === '' || ($type === 'match' && ($match === '' || \core_text::strlen($match) > 255))) {
                throw self::invalid('question answer');
            }
            $right += $answer['correct'] ? 1 : 0;
            $question['answers'][] = ['text' => $text, 'correct' => $answer['correct'],
                'feedback' => self::shown($answer['feedback']), 'match' => $match];
        }
        $count = count($question['answers']);
        $complete = [
            'multichoice' => $count >= 2 && $right >= 1,
            'truefalse' => $count === 2 && $right === 1,
            'shortanswer' => $count >= 1 && $right >= 1,
            'match' => $count >= 2,
        ];
        if ($question['text'] === '' || !$complete[$type]) {
            throw self::invalid('question incomplete');
        }
        if ($question['name'] === '') {
            $question['name'] = \core_text::substr(self::no_markup(self::plain($question['text'])), 0, 80);
        }
        return $question;
    }

    /**
     * Refuses a value that is not an object with exactly these fields.
     *
     * @param mixed $value
     * @param string[] $fields
     * @param string[] $optional
     */
    protected static function check_fields($value, array $fields, array $optional = []): void {
        if (
            !is_array($value) || array_diff(array_keys($value), $fields, $optional) || array_diff($fields, array_keys($value))
        ) {
            throw self::invalid('fields of a question list');
        }
    }

    /**
     * A string within the limit, without control zero.
     *
     * @param mixed $value
     * @return string
     */
    protected static function raw($value): string {
        if (!is_string($value) || strlen($value) > self::MAX_HTML || strpos($value, "\0") !== false) {
            throw self::invalid('question text');
        }
        return $value;
    }

    /**
     * HTML that will be shown. An image comes as a file: one embedded in the HTML would be taken out in silence when cleaning.
     *
     * @param mixed $value
     * @return string
     */
    protected static function shown($value): string {
        if (preg_match('~<img\b[^>]*data:~i', self::raw($value))) {
            throw self::invalid('embedded image');
        }
        return self::html($value);
    }

    /**
     * The images by digest that the questions cite.
     *
     * @param array $items Questions of parse().
     * @return string[]
     */
    public static function cited_images(array $items): array {
        $html = [];
        foreach ($items as $question) {
            $html[] = $question['text'];
            $html[] = $question['feedback'];
            foreach ($question['answers'] as $answer) {
                if (in_array($question['type'], ['multichoice', 'match'], true)) {
                    $html[] = $answer['text'];
                }
                $html[] = $answer['feedback'];
            }
        }
        return array_values(array_filter(
            update_classifier::cited_files(implode("\n", $html)),
            fn(string $name): bool => (bool) preg_match(update_page_files::HASHED, $name)
        ));
    }

    /**
     * HTML as it is kept: Moodle shows the text of a question without cleaning it, so it is cleaned here.
     *
     * @param mixed $value
     * @return string
     */
    public static function html($value): string {
        $value = trim(self::raw($value));
        return $value === '' ? '' : trim(clean_text($value, FORMAT_HTML));
    }

    /**
     * Plain text that can open no tag: Moodle shows the name of a question without escaping it in the statistics report, and
     * the text of a short answer goes the same way.
     *
     * @param string $text
     * @return string
     */
    protected static function no_markup(string $text): string {
        return (string) preg_replace('~<(?=[A-Za-z/!?])~', '< ', $text);
    }

    /**
     * The words of an HTML, to compare two of them.
     *
     * @param string $html
     * @return string
     */
    public static function plain(string $html): string {
        $text = strip_tags(str_replace('<', ' <', $html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The exception of a list that is refused.
     *
     * @param string $detail
     * @return moodle_exception
     */
    protected static function invalid(string $detail): moodle_exception {
        return new moodle_exception('error:invalidoperations', 'local_miwlab', '', $detail);
    }
}
