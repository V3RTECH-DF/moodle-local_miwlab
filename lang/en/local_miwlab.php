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

$string['accountdescription'] = 'Account of the MiwLab key, created by the MiwLab plugin. Nobody signs in with it: it only answers the web service calls of the MiwLab.';
$string['accountfirstname'] = 'MiwLab';
$string['accountlastname'] = 'Integration';
$string['confirmgenerate'] = 'Generate a new MiwLab key? The current key stops working at once: paste the new one in the MiwLab (Organização > Plataforma de ensino).';
$string['confirmremove'] = 'Delete the MiwLab account? Its key stops working and the MiwLab loses the connection until a new key is generated.';
$string['confirmrevoke'] = 'Revoke the MiwLab key? The MiwLab loses the connection until a new key is generated. The account and its role stay.';
$string['error:backupempty'] = 'Moodle made no backup file.';
$string['error:backupfailed'] = 'The backup of the course could not be made, so the course was not replaced: {$a}';
$string['error:backupnotfound'] = 'The content backup does not exist (it may have been deleted after a day).';
$string['error:backupwithpeople'] = 'The backup would carry data of people (users, enrolments, completion, logs) and was not made: on the site, the backup settings of users are locked on. A site administrator unlocks them in Site administration > Courses > Backups > General backup defaults.';
$string['error:cannotkeepenrolments'] = 'The restore could not keep the enrolments, roles and groups of the course (the account needs moodle/restore:configure in the course), so the course was not replaced.';
$string['error:categorynotfound'] = 'The category does not exist.';
$string['error:contentbackupfailed'] = 'The backup of the content of the course could not be made.';
$string['error:coursechanged'] = 'The course changed in Moodle since the MiwLab last knew it (sent it, read it or realigned it), so it was not replaced: realign the course in the MiwLab first (Entregas > Realinhar com a plataforma).';
$string['error:coursedeleted'] = 'The course was restored, but it has been deleted since.';
$string['error:coursenotfound'] = 'The course does not exist.';
$string['error:draftfileempty'] = 'The package in the draft area is empty.';
$string['error:draftfilenotfound'] = 'The package was not found in the draft area of the account. Upload it again (webservice/upload.php) and use the itemid returned.';
$string['error:draftfiletoomany'] = 'The draft area has more than one file: give the name of the package.';
$string['error:feedbacknotfound'] = 'The feedback does not exist.';
$string['error:forumcapability'] = 'The account cannot start discussions in the forum: its role lacks {$a}.';
$string['error:forumdiscussion'] = 'Moodle did not create the topic "{$a}".';
$string['error:forumnotfound'] = 'The forum "{$a}" is not in the restored course, so its topics were not created.';
$string['error:forumtopicsinvalid'] = 'The topics of the forums that the package carries are not in the format the MiwLab writes, so none was created.';
$string['error:forumtopicskeep'] = 'The topics of the package could not be set aside before the restore, so none was created.';
$string['error:hasprogress'] = '{$a->people} of the people in this course have progress (an activity or the course completed, a grade, a submission, an attempt, an answer, a post or a note), and replacing the content would delete it. The course is not replaced: create a new course and hide this one instead.';
$string['error:invalidmarker'] = 'The marker must start with "miwlab:" and have only letters, digits, "-", "_" and at most one more ":".';
$string['error:missingcapabilities'] = 'The account cannot restore in the category "{$a->category}": its role there lacks {$a->capabilities}.';
$string['error:missingcontentcapabilities'] = 'The account cannot back up the content of this course: its role there lacks {$a}.';
$string['error:missingcoursecapabilities'] = 'The account cannot do this in the course "{$a->course}": its role there lacks {$a->capabilities}.';
$string['error:noservice'] = 'The "MiwLab (local_miwlab)" external service is missing and could not be created again from the plugin. Open Notifications, in Site administration, and try again.';
$string['error:notabackup'] = 'The file is not a Moodle course backup (.mbz).';
$string['error:notanonymous'] = 'The feedback is not anonymous: its answers are not read.';
$string['error:precheck'] = 'Moodle refused the package before restoring it: {$a}';
$string['error:replacefailed'] = 'The replacement failed after the content was deleted; the backup made before was restored into the course, which is as it was.';
$string['error:replaceinprogress'] = 'A replacement of this course is already queued or running. Wait for it to end.';
$string['error:restorenotfound'] = 'There is no restore with this identifier (records are kept for 30 days after the end).';
$string['error:rolenameinuse'] = 'There is already a role with the short name "{$a}" that the plugin did not create. Rename it (Define roles) and try again.';
$string['error:rollbackfailed'] = 'The replacement failed after the content was deleted, and restoring the backup made before failed too. The backup is in the course backup area (Course > Restore): restore it into the course.';
$string['error:stalled'] = 'The restore did not end within 48 hours: the background task stopped. Check that the cron of Moodle is running and queue it again.';
$string['error:unknownmodules'] = 'The course has activities of a type whose progress the plugin cannot read ({$a}), so it is not replaced: the work of the people there could not be checked. Create a new course and hide this one instead.';
$string['error:usernameinuse'] = 'There is already an account with the user name "{$a}" that the plugin did not create. Rename it or delete it and try again.';
$string['generate'] = 'Generate the MiwLab key';
$string['generatehelp'] = 'Generating the key turns on web services and the REST protocol, creates the account "local_miwlab" (it cannot sign in; its e-mail address is fictitious, local_miwlab@example.invalid, and never receives messages), gives it the role "MiwLab" at site level, authorises it in the "MiwLab (local_miwlab)" external service and creates its token.';
$string['keygenerated'] = 'The MiwLab key is ready. Copy the key and the site address below and paste them in the MiwLab, in Organização > Plataforma de ensino.';
$string['keyintro'] = 'The MiwLab reads the courses of this site, lists its activity types, restores the courses it designs as new courses or, when nobody has progress there, in place of the course they came from (after a backup of it), and reads consolidated completion and anonymous feedback, with a web service key. On install the plugin created the "MiwLab (local_miwlab)" external service, with the functions the MiwLab calls, and the "MiwLab" role, with only the capabilities they need. The account and its key are created only here, on your click.';
$string['keyonce'] = 'The key is shown only now. If it is lost, generate a new one: the old one stops working.';
$string['keypage'] = 'MiwLab key';
$string['miwlab:backupcourse'] = 'Back up the content of a course for the MiwLab, without any data of a person';
$string['miwlab:hidecourse'] = 'Hide a course, for the MiwLab, once a new course takes its place';
$string['miwlab:readforumtopics'] = 'Read the opening topics that the staff of a course started in its forums, for the MiwLab';
$string['miwlab:replacecourse'] = 'Replace the content of a course with a MiwLab package, after a backup without user data (with progress there, only when the replacement asks to discard it, after a conscious confirmation)';
$string['miwlab:restorecourse'] = 'Restore a MiwLab package as a new course';
$string['miwlab:viewmoduletypes'] = 'List the activity and resource types and the course formats installed on the site, for the MiwLab';
$string['miwlab:viewreports'] = 'Read consolidated counts for the MiwLab (completion and anonymous feedback of a course, and the counts of the courses of whole categories)';
$string['noaccount'] = 'There was no MiwLab account to delete.';
$string['pluginname'] = 'MiwLab';
$string['privacy:metadata'] = 'The MiwLab plugin stores no personal data: it returns counts only, and keeps the progress of each restore without the person who asked for it. The backup made before replacing a course has no user data. The account of the MiwLab key, when a site administrator generates it, is an ordinary Moodle account of the site, with a fictitious e-mail address; the plugin keeps only its id.';
$string['regenerate'] = 'Generate a new key';
$string['remove'] = 'Delete the MiwLab account';
$string['removed'] = 'The MiwLab account was deleted.';
$string['revoke'] = 'Revoke the key';
$string['revoked'] = 'Keys revoked: {$a}.';
$string['roledescription'] = 'Role of the MiwLab key, created by the MiwLab plugin: only the capabilities of the web service functions the MiwLab calls. Given at site level to the MiwLab account.';
$string['rolename'] = 'MiwLab';
$string['siteaddress'] = 'Site address';
$string['statusaccount'] = 'MiwLab account';
$string['statusauth'] = 'Web service authentication enabled';
$string['statuskey'] = 'Key generated';
$string['statusrole'] = 'Role "MiwLab"';
$string['statusservice'] = 'Service "MiwLab (local_miwlab)" enabled';
$string['statuswebservices'] = 'Web services and REST on';
$string['task:cleanuprestores'] = 'Forget the progress of old MiwLab restores';
$string['thekey'] = 'Key';
