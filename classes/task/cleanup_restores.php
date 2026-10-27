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

namespace local_miwlab\task;

/**
 * Forgets the progress of restores that ended long ago.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_restores extends \core\task\scheduled_task {
    /** Days the progress of an ended restore is kept. */
    const KEEP_DAYS = 30;

    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:cleanuprestores', 'local_miwlab');
    }

    /** Hours after which a restore still queued or running is taken as failed (its background task stopped). */
    const STALLED_HOURS = 48;

    /**
     * Marks stalled restores as failed and deletes the records of restores ended more than KEEP_DAYS days ago.
     */
    public function execute() {
        global $DB;
        $now = time();
        $DB->execute(
            "UPDATE {local_miwlab_restores}
                SET status = :failed, errorcode = :code, errormessage = :message, timefinished = :now
              WHERE status IN (:queued, :running) AND timecreated < :limit",
            [
                'failed' => \local_miwlab\local\restorer::STATUS_FAILED,
                'code' => 'error:stalled',
                'message' => get_string('error:stalled', 'local_miwlab'),
                'now' => $now,
                'queued' => \local_miwlab\local\restorer::STATUS_QUEUED,
                'running' => \local_miwlab\local\restorer::STATUS_RUNNING,
                'limit' => $now - self::STALLED_HOURS * HOURSECS,
            ]
        );
        // 1.6.0: the content backups the MiwLab did not delete (downloaded, failed or stalled) go after a day.
        \local_miwlab\local\exporter::cleanup();
        // 1.9.0 (issue 204): the full backups (with the people) whose days are over, only the files the plugin registered.
        \local_miwlab\local\fullcopies::cleanup();
        // 1.9.0 (issue 204): the previews of the safe update that expired, and the packages kept for them.
        \local_miwlab\local\updater::cleanup();
        $DB->delete_records_select(
            'local_miwlab_restores',
            'status IN (:finished, :failed) AND timefinished < :limit',
            [
                'finished' => \local_miwlab\local\restorer::STATUS_FINISHED,
                'failed' => \local_miwlab\local\restorer::STATUS_FAILED,
                'limit' => $now - self::KEEP_DAYS * DAYSECS,
            ]
        );
    }
}
