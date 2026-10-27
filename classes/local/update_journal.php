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
 * The undo journal of a safe update (1.9.0, issue 204). The update runs with people in the course, so a failure in the middle
 * cannot
 * be undone by restoring the backup (it has no people: restoring it would delete their progress). Instead, before touching a row
 * the journal keeps it as it was; undo() puts the rows back exactly (same values, same modification times), deletes what the update
 * created (they hold no data of anybody) and rebuilds the cache, so the state signature of the course is the one from before.
 * It lives in memory for the length of the run and is never written anywhere: it can hold rows with data of people (the course
 * completion, when the person chose to replace it, which only happens together with a full backup).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_journal {
    /** @var int */
    protected $courseid;
    /** @var array[] course_sections rows before, by id. */
    protected $sections = [];
    /** @var array[] course_modules rows before, by id. */
    protected $cms = [];
    /** @var array[] [table, id] => row before. */
    protected $instances = [];
    /** @var array[] grade_items rows before, by id. */
    protected $gradeitems = [];
    /** @var string[] module => instance whose events must be refreshed after undo. */
    protected $events = [];
    /** @var array|null Course completion before: criteria, aggregation, completions, criteria completions. */
    protected $completion = null;
    /** @var array|null The root category of the gradebook before. */
    protected $category = null;
    /** @var array[]|null block_instances rows before, by id. */
    protected $blocks = null;
    /** @var int[] Blocks the update created. */
    protected $createdblocks = [];
    /** @var int[] Activities that existed before. */
    protected $beforecms = [];
    /** @var int[] Sections that existed before. */
    protected $beforesections = [];

    /**
     * Takes the picture of the structure of the course.
     *
     * @param int $courseid
     */
    public function __construct(int $courseid) {
        global $DB;
        $this->courseid = $courseid;
        foreach ($DB->get_records('course_sections', ['course' => $courseid]) as $r) {
            $this->sections[(int) $r->id] = (array) $r;
        }
        foreach ($DB->get_records('course_modules', ['course' => $courseid]) as $r) {
            $this->cms[(int) $r->id] = (array) $r;
        }
        $this->beforecms = array_keys($this->cms);
        $this->beforesections = array_keys($this->sections);
    }

    /**
     * The activities there were before the update.
     *
     * @return int[] The activities that existed before the update.
     */
    public function cm_ids_before(): array {
        return $this->beforecms;
    }

    /**
     * The sections there were before the update.
     *
     * @return int[] The sections that existed before the update.
     */
    public function section_ids_before(): array {
        return $this->beforesections;
    }

    /**
     * Keeps a module instance (and its grade item) as it is, once.
     *
     * @param string $module
     * @param int $instance
     */
    public function remember_instance(string $module, int $instance): void {
        global $DB;
        $key = $module . ':' . $instance;
        if (isset($this->instances[$key])) {
            return;
        }
        $row = $DB->get_record($module, ['id' => $instance]);
        if ($row) {
            $this->instances[$key] = [$module, (array) $row];
        }
        $items = $DB->get_records(
            'grade_items',
            ['courseid' => $this->courseid, 'itemmodule' => $module, 'iteminstance' => $instance]
        );
        foreach ($items as $g) {
            $this->gradeitems[(int) $g->id] = (array) $g;
        }
    }

    /**
     * The calendar events of an instance are written again after the undo (a date was changed).
     *
     * @param string $module
     * @param int $instance
     */
    public function remember_events(string $module, int $instance): void {
        $this->events[$module . ':' . $instance] = [$module, $instance];
    }

    /**
     * The records of a query as plain arrays, in order.
     *
     * @param array $records
     * @return array
     */
    protected static function rows(array $records): array {
        return array_map(fn($r) => (array) $r, array_values($records));
    }

    /**
     * Keeps the course completion criteria and what people have of them.
     */
    public function remember_completion(): void {
        global $DB;
        if ($this->completion !== null) {
            return;
        }
        $c = $this->courseid;
        $this->completion = [
            'criteria' => self::rows($DB->get_records('course_completion_criteria', ['course' => $c])),
            'aggr' => self::rows($DB->get_records('course_completion_aggr_methd', ['course' => $c])),
            'completions' => self::rows($DB->get_records('course_completions', ['course' => $c])),
            'crit' => self::rows($DB->get_records('course_completion_crit_compl', ['course' => $c])),
        ];
    }

    /**
     * Keeps the root category of the gradebook.
     */
    public function remember_category(): void {
        global $DB;
        if ($this->category !== null) {
            return;
        }
        $item = $DB->get_record('grade_items', ['courseid' => $this->courseid, 'itemtype' => 'course']);
        $cat = $item ? $DB->get_record('grade_categories', ['id' => $item->iteminstance]) : false;
        $this->category = $cat ? (array) $cat : [];
    }

    /**
     * Keeps the block instances of the course page.
     */
    public function remember_blocks(): void {
        global $DB;
        if ($this->blocks !== null) {
            return;
        }
        $this->blocks = [];
        $context = \context_course::instance($this->courseid);
        foreach ($DB->get_records('block_instances', ['parentcontextid' => $context->id]) as $r) {
            $this->blocks[(int) $r->id] = (array) $r;
        }
    }

    /**
     * Remembers a block the update created, to delete it if the update is undone.
     *
     * @param int $instanceid A block the update created.
     */
    public function created_block(int $instanceid): void {
        $this->createdblocks[] = $instanceid;
    }

    /**
     * Puts everything back as it was.
     *
     * @param stdClass $course
     */
    public function undo(stdClass $course): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/blocklib.php');
        require_once($CFG->libdir . '/gradelib.php');
        // What the update created has no data of anybody: activities (with the question bank the restore may have brought),
        // sections.
        $now = $DB->get_fieldset_select('course_modules', 'id', 'course = ?', [$this->courseid]);
        foreach (array_reverse(array_diff($now, $this->beforecms)) as $cmid) {
            compat::delete_cm($this->courseid, (int) $cmid);
        }
        $nowsections = $DB->get_records('course_sections', ['course' => $this->courseid], 'section DESC');
        foreach ($nowsections as $s) {
            if (!in_array((int) $s->id, $this->beforesections, true)) {
                course_delete_section($course, $s, true);
            }
        }
        foreach ($this->createdblocks as $id) {
            $row = $DB->get_record('block_instances', ['id' => $id]);
            if ($row) {
                blocks_delete_instance($row);
            }
        }
        // The rows, exactly as they were.
        foreach ($this->instances as [$module, $row]) {
            $DB->update_record($module, (object) $row);
        }
        foreach ($this->gradeitems as $row) {
            $DB->update_record('grade_items', (object) $row);
        }
        foreach ($this->cms as $row) {
            if ($DB->record_exists('course_modules', ['id' => $row['id']])) {
                $DB->update_record('course_modules', (object) $row);
            }
        }
        foreach ($this->sections as $row) {
            $DB->update_record('course_sections', (object) $row);
        }
        if ($this->completion !== null) {
            $this->undo_completion();
        }
        if ($this->category) {
            $DB->update_record('grade_categories', (object) $this->category);
            grade_regrade_final_grades($this->courseid);
        }
        if ($this->blocks !== null) {
            $this->undo_blocks();
        }
        foreach ($this->events as [$module, $instance]) {
            $cm = get_coursemodule_from_instance($module, $instance, $this->courseid);
            if ($cm) {
                course_module_update_calendar_events($module, $DB->get_record($module, ['id' => $instance]), $cm);
            }
        }
        \cache_helper::purge_by_event('changesincourse');
        rebuild_course_cache($this->courseid, true);
    }

    /**
     * The completion criteria and the completions of people, back.
     */
    protected function undo_completion(): void {
        global $DB;
        $c = $this->courseid;
        $tables = [
            'criteria' => 'course_completion_criteria',
            'aggr' => 'course_completion_aggr_methd',
            'completions' => 'course_completions',
            'crit' => 'course_completion_crit_compl',
        ];
        foreach ($tables as $t) {
            $DB->delete_records($t, ['course' => $c]);
        }
        foreach ($tables as $key => $table) {
            foreach ($this->completion[$key] as $row) {
                $DB->import_record($table, (object) $row);
            }
        }
    }

    /**
     * The block instances, back (the ones removed by the update hold no data of a person, by rule).
     */
    protected function undo_blocks(): void {
        global $DB;
        foreach ($this->blocks as $id => $row) {
            if ($DB->record_exists('block_instances', ['id' => $id])) {
                $DB->update_record('block_instances', (object) $row);
            } else {
                $DB->import_record('block_instances', (object) $row);
                \context_block::instance($id);
            }
        }
    }
}
