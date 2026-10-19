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
 * Restores a MiwLab package queued by the web service, as the user who queued it.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_course extends \core\task\adhoc_task {
    /**
     * Runs the restore. A failure is recorded in the restore itself, so the task never retries.
     */
    public function execute() {
        $data = $this->get_custom_data();
        \local_miwlab\local\restorer::run((int) $data->id, (int) $data->fileid);
    }

    /**
     * A restore is not retried: the failure and its reason are recorded for the MiwLab.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }
}
