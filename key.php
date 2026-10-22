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
 * The page of the MiwLab key: shows what is in place and, on the click of a site administrator, generates the key
 * (account, role, authorisation in the service and token), revokes it or removes the account.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_miwlab\local\provisioning;

admin_externalpage_setup('local_miwlab_key');
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$url = new moodle_url('/local/miwlab/key.php');
$PAGE->set_url($url);
$PAGE->set_title(get_string('keypage', 'local_miwlab'));
$PAGE->set_heading(get_string('keypage', 'local_miwlab'));

$status = provisioning::status();
$token = null;
$message = null;

if ($action !== '') {
    require_sesskey();
    $needsconfirm = ($action === 'generate' && $status['tokens'] > 0) || $action === 'revoke' || $action === 'remove';
    if (!in_array($action, ['generate', 'revoke', 'remove'])) {
        throw new moodle_exception('invalidparameter', 'debug');
    }
    if ($needsconfirm && !$confirm) {
        echo $OUTPUT->header();
        $yes = new moodle_url($url, ['action' => $action, 'confirm' => 1, 'sesskey' => sesskey()]);
        echo $OUTPUT->confirm(
            get_string('confirm' . $action, 'local_miwlab'),
            new single_button($yes, get_string('continue'), 'post'),
            new single_button($url, get_string('cancel'), 'get')
        );
        echo $OUTPUT->footer();
        exit;
    }
    if ($action === 'generate') {
        $token = provisioning::generate_key();
    } else if ($action === 'revoke') {
        $message = get_string('revoked', 'local_miwlab', provisioning::revoke_key());
    } else {
        $message = get_string(provisioning::remove_account() ? 'removed' : 'noaccount', 'local_miwlab');
    }
    $status = provisioning::status();
}

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('keyintro', 'local_miwlab'));

if ($token !== null) {
    echo $OUTPUT->notification(get_string('keygenerated', 'local_miwlab'), \core\output\notification::NOTIFY_SUCCESS);
    echo html_writer::start_tag('dl', ['class' => 'row']);
    echo html_writer::tag('dt', get_string('siteaddress', 'local_miwlab'), ['class' => 'col-sm-3']);
    echo html_writer::tag('dd', html_writer::tag('code', s($CFG->wwwroot)), ['class' => 'col-sm-9']);
    echo html_writer::tag('dt', get_string('thekey', 'local_miwlab'), ['class' => 'col-sm-3']);
    echo html_writer::tag('dd', html_writer::tag('code', s($token), ['id' => 'local_miwlab_token']), ['class' => 'col-sm-9']);
    echo html_writer::end_tag('dl');
    echo html_writer::tag('p', get_string('keyonce', 'local_miwlab'));
} else if ($message !== null) {
    echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_INFO);
}

$yesno = function (bool $ok): string {
    return get_string($ok ? 'yes' : 'no');
};
$table = new html_table();
$table->attributes['class'] = 'generaltable';
$table->data = [
    [get_string('statuswebservices', 'local_miwlab'), $yesno($status['webservices'] && $status['rest'])],
    [get_string('statusauth', 'local_miwlab'), $yesno($status['auth'])],
    [get_string('statusservice', 'local_miwlab'), $status['service'] === null ? get_string('no') : $yesno($status['service'])],
    [get_string('statusrole', 'local_miwlab'), $yesno($status['role'])],
    [get_string('statusaccount', 'local_miwlab'), $status['account'] === null ? get_string('no') : s($status['account'])],
    [get_string('statuskey', 'local_miwlab'), $yesno($status['tokens'] > 0)],
];
echo html_writer::table($table);

$button = function (string $action, string $label) use ($url): string {
    $class = $action === 'generate' ? 'btn btn-primary' : 'btn btn-secondary';
    $fields = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]) .
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
        html_writer::tag('button', $label, ['type' => 'submit', 'class' => $class]);
    $attributes = ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-block me-2 mr-2 mb-2'];
    return html_writer::tag('form', $fields, $attributes);
};
echo html_writer::tag('p', get_string('generatehelp', 'local_miwlab'));
echo $button('generate', get_string($status['tokens'] > 0 ? 'regenerate' : 'generate', 'local_miwlab'));
if ($status['tokens'] > 0) {
    echo $button('revoke', get_string('revoke', 'local_miwlab'));
}
if ($status['account'] !== null) {
    echo $button('remove', get_string('remove', 'local_miwlab'));
}
echo $OUTPUT->footer();
