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
 * The small, single-purpose changes a safe update makes (1.9.0, issue 204): one function per field. Each one keeps the row in the
 * journal first, uses the API of Moodle where there is one (rename, visibility, section update, block and completion
 * functions) and,
 * where there is none (the text of an activity, its completion rule, availability and dates), writes the column and does what the
 * Moodle editing form does after (the modification time, the event, the calendar, the cache). Justification and test per type:
 * Phase 0 of issue 204.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_appliers {
    /** @var update_journal */
    protected $journal;
    /** @var stdClass */
    protected $course;

    /**
     * Prepares the appliers for a course, loading the Moodle libraries they use.
     *
     * @param stdClass $course
     * @param update_journal $journal
     */
    public function __construct(stdClass $course, update_journal $journal) {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/blocklib.php');
        $this->course = $course;
        $this->journal = $journal;
    }

    /**
     * The course module of an activity of this course.
     *
     * @param int $cmid
     * @return \stdClass The course module (get_coursemodule_from_id).
     */
    protected function cm(int $cmid): stdClass {
        return get_coursemodule_from_id('', $cmid, $this->course->id, false, MUST_EXIST);
    }

    /**
     * Keeps the instance of an activity before an API of Moodle that may touch it (hiding changes its modification time in 5.x).
     *
     * @param int $cmid
     */
    protected function remember_cm(int $cmid): void {
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
    }

    /**
     * Tells Moodle an activity was edited.
     *
     * @param stdClass $cm
     */
    protected function touched(stdClass $cm): void {
        \core\event\course_module_updated::create_from_cm($cm)->trigger();
        \course_modinfo::purge_course_module_cache($this->course->id, $cm->id);
    }

    /**
     * Renames an activity.
     *
     * @param int $cmid
     * @param string $name
     */
    public function name(int $cmid, string $name): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
        set_coursemodule_name($cmid, $name);
    }

    /**
     * The main text of an activity: the content of a page, the description of any other.
     *
     * @param int $cmid
     * @param string $field
     * @param string $html
     */
    public function text(int $cmid, string $field, string $html): void {
        global $CFG, $DB;
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
        $upd = (object) ['id' => $cm->instance, $field => $html, 'timemodified' => time()];
        if (array_key_exists($field . 'format', $DB->get_columns($cm->modname))) {
            $format = $field . 'format';
            $upd->$format = FORMAT_HTML;
        }
        if ($cm->modname === 'page') {
            $upd->revision = ((int) $DB->get_field('page', 'revision', ['id' => $cm->instance])) + 1;
        }
        if ($cm->modname === 'label') {
            // A label has no name of its own: Moodle derives it from the text.
            require_once($CFG->dirroot . '/mod/label/lib.php');
            $upd->name = get_label_name((object) ['coursemodule' => $cm->id, 'name' => '', 'intro' => $html,
                'introformat' => FORMAT_HTML]);
        }
        $DB->update_record($cm->modname, $upd);
        $this->touched($cm);
    }

    /**
     * The completion rule of an activity (nobody has progress there: the classifier saw to that).
     *
     * @param int $cmid
     * @param array $completion
     */
    public function completion(int $cmid, array $completion): void {
        global $DB;
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
        $completion = update_classifier::normalize_completion($cmid, $completion);
        $columns = $DB->get_columns('course_modules');
        $upd = (object) ['id' => $cmid];
        foreach (update_operations::COMPLETION as $col) {
            if (isset($columns[$col]) && array_key_exists($col, $completion)) {
                $upd->$col = $completion[$col];
            }
        }
        $DB->update_record('course_modules', $upd);
        if ($completion['type']) {
            $typeupd = (object) ['id' => $cm->instance, 'timemodified' => time()];
            foreach ($completion['type'] as $col => $v) {
                $typeupd->$col = $v;
            }
            $DB->update_record($cm->modname, $typeupd);
        }
        $this->touched($this->cm($cmid));
    }

    /**
     * Sets the access restriction of an activity.
     *
     * @param int $cmid
     * @param string|null $availability
     */
    public function availability(int $cmid, ?string $availability): void {
        global $DB;
        $DB->set_field('course_modules', 'availability', $availability, ['id' => $cmid]);
        $this->touched($this->cm($cmid));
    }

    /**
     * Shows or hides an activity.
     *
     * @param int $cmid
     * @param bool $visible
     */
    public function visible(int $cmid, bool $visible): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->remember_cm($cmid);
        set_coursemodule_visible($cmid, $visible ? 1 : 0);
    }

    /**
     * Changes the dates of an activity and refreshes its events in the calendar.
     *
     * @param int $cmid
     * @param array $dates column => unix time
     */
    public function dates(int $cmid, array $dates): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
        $this->journal->remember_events($cm->modname, (int) $cm->instance);
        $upd = (object) ['id' => $cm->instance, 'timemodified' => time()];
        foreach ($dates as $col => $v) {
            $upd->$col = $v;
        }
        $DB->update_record($cm->modname, $upd);
        course_module_update_calendar_events($cm->modname, $DB->get_record($cm->modname, ['id' => $cm->instance]), $cm);
        $this->touched($cm);
    }

    /**
     * The maximum grade of an activity nobody has progress in, and its grade item.
     *
     * @param int $cmid
     * @param float $max
     */
    public function grademax(int $cmid, float $max): void {
        global $CFG, $DB;
        $cm = $this->cm($cmid);
        $this->journal->remember_instance($cm->modname, (int) $cm->instance);
        $DB->update_record($cm->modname, (object) ['id' => $cm->instance, 'grade' => $max, 'timemodified' => time()]);
        require_once($CFG->dirroot . '/mod/' . $cm->modname . '/lib.php');
        $function = update_classifier::GRADE_FUNCTIONS[$cm->modname];
        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        $instance->cmidnumber = $cm->idnumber;
        $function($instance);
        $this->touched($cm);
    }

    /**
     * An activity the MiwLab removed: hidden, never deleted.
     *
     * @param int $cmid
     */
    public function hide(int $cmid): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->remember_cm($cmid);
        set_coursemodule_visible($cmid, 0);
    }

    /**
     * The name or the summary of a section.
     *
     * @param int $sectionid
     * @param array $data
     */
    public function section(int $sectionid, array $data): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $this->course->id], '*', MUST_EXIST);
        if (isset($data['summary'])) {
            $data['summaryformat'] = FORMAT_HTML;
        }
        course_update_section($this->course, $section, $data);
    }

    /**
     * A section the MiwLab removed: hidden, with its activities, never deleted.
     *
     * @param int $sectionid
     */
    public function hide_section(int $sectionid): void {
        global $DB;
        $section = $DB->get_record('course_sections', ['id' => $sectionid, 'course' => $this->course->id], '*', MUST_EXIST);
        foreach (array_filter(explode(',', (string) $section->sequence)) as $cmid) {
            $this->remember_cm((int) $cmid);
        }
        compat::set_section_visible($this->course, $section, false);
    }

    /**
     * Replaces the course completion criteria: the ones the course has go (and, with them, the course completion of the people who
     * had concluded it: the journal keeps it for the undo and the full backup is the way back), and the new ones come.
     *
     * @param array $setting
     */
    public function completion_criteria(array $setting): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->dirroot . '/completion/criteria/completion_criteria_course.php');
        $this->journal->remember_completion();
        $completion = new \completion_info(get_course($this->course->id));
        $completion->clear_criteria();
        $ids = array_map(fn($k) => (int) $k['courseinstance'], $setting['criteria']);
        if ($ids) {
            $data = (object) ['id' => $this->course->id, 'criteria_course' => $ids, 'course_aggregation' => $setting['aggregation'],
                'overall_aggregation' => $setting['aggregation']];
            $criterion = new \completion_criteria_course();
            $criterion->update_config($data);
        }
    }

    /**
     * Sets the settings of the root category of the gradebook and recalculates the totals (the grades of the activities stay; a
     * total typed by hand stays).
     *
     * @param array $category
     */
    public function gradebook(array $category): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/grade_category.php');
        $this->journal->remember_category();
        $cat = \grade_category::fetch_course_category($this->course->id);
        foreach ($category as $k => $v) {
            $cat->$k = $v;
        }
        $cat->update();
        grade_regrade_final_grades($this->course->id);
    }

    /**
     * Adds, replaces the configuration of, or removes a block of the course page.
     *
     * @param array $block
     */
    public function block(array $block): void {
        global $DB;
        $this->journal->remember_blocks();
        $config = array_key_exists('config', $block) ? base64_encode(serialize((object) $block['config'])) : null;
        if ($block['proposal'] === 'remove') {
            blocks_delete_instance($DB->get_record('block_instances', ['id' => $block['instanceid']], '*', MUST_EXIST));
        } else if ($block['proposal'] === 'replace') {
            $DB->set_field('block_instances', 'configdata', $config ?? '', ['id' => $block['instanceid']]);
            $DB->set_field('block_instances', 'timemodified', time(), ['id' => $block['instanceid']]);
        } else {
            $page = new \moodle_page();
            $page->set_context(\context_course::instance($this->course->id));
            $page->set_pagetype('course-view-topics');
            $page->set_url('/course/view.php', ['id' => $this->course->id]);
            $page->set_course($this->course);
            $page->blocks->add_regions(['side-pre']);
            $before = $DB->get_fieldset_select(
                'block_instances',
                'id',
                'parentcontextid = ? AND blockname = ?',
                [\context_course::instance($this->course->id)->id, $block['name']]
            );
            $page->blocks->add_block($block['name'], 'side-pre', 0, false, 'course-view-*');
            $after = $DB->get_fieldset_select(
                'block_instances',
                'id',
                'parentcontextid = ? AND blockname = ?',
                [\context_course::instance($this->course->id)->id, $block['name']]
            );
            foreach (array_diff($after, $before) as $id) {
                $this->journal->created_block((int) $id);
                if ($config !== null) {
                    $DB->set_field('block_instances', 'configdata', $config, ['id' => $id]);
                }
            }
        }
    }
}
