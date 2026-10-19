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

/**
 * English strings of the MiwLab companion plugin.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['error:categorynotfound'] = 'The category does not exist.';
$string['error:coursedeleted'] = 'The course was restored, but it has been deleted since.';
$string['error:coursenotfound'] = 'The course does not exist.';
$string['error:draftfileempty'] = 'The package in the draft area is empty.';
$string['error:draftfilenotfound'] = 'The package was not found in the draft area of the account. Upload it again (webservice/upload.php) and use the itemid returned.';
$string['error:draftfiletoomany'] = 'The draft area has more than one file: give the name of the package.';
$string['error:feedbacknotfound'] = 'The feedback does not exist.';
$string['error:invalidmarker'] = 'The marker must start with "miwlab:" and have only letters, digits, "-", "_" and at most one more ":".';
$string['error:missingcapabilities'] = 'The account cannot restore in the category "{$a->category}": its role there lacks {$a->capabilities}.';
$string['error:notabackup'] = 'The file is not a Moodle course backup (.mbz).';
$string['error:notanonymous'] = 'The feedback is not anonymous: its answers are not read.';
$string['error:precheck'] = 'Moodle refused the package before restoring it: {$a}';
$string['error:restorenotfound'] = 'There is no restore with this identifier (records are kept for 30 days after the end).';
$string['error:stalled'] = 'The restore did not end within 48 hours: the background task stopped. Check that the cron of Moodle is running and queue it again.';
$string['miwlab:restorecourse'] = 'Restore a MiwLab package as a new course';
$string['miwlab:viewreports'] = 'Read consolidated counts of a course for the MiwLab (completion and anonymous feedback)';
$string['pluginname'] = 'MiwLab';
$string['privacy:metadata'] = 'The MiwLab plugin stores no personal data: it returns counts only, and keeps the progress of each restore without the person who asked for it.';
$string['task:cleanuprestores'] = 'Forget the progress of old MiwLab restores';
