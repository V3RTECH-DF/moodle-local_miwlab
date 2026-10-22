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
 * Install of the MiwLab companion plugin: the name of its external service freed, and the system role "MiwLab" (no
 * account is created here).
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates the role of the MiwLab key.
 *
 * @return bool
 */
function xmldb_local_miwlab_install() {
    // A service made by hand with the name of the plugin service would stop the install (the name is unique).
    \local_miwlab\local\provisioning::prepare_service();
    \local_miwlab\local\provisioning::ensure_role();
    return true;
}
