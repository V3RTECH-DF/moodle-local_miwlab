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

namespace local_miwlab\external;

/**
 * The limits that can refuse a file sent to the site by the web service upload (webservice/upload.php), in bytes.
 *
 * The MiwLab sends the package of a course that way. Three limits apply, and the one in force is the smallest: the PHP
 * setting upload_max_filesize, the PHP setting post_max_size and the maximum upload size of the site (Site administration >
 * Security > Site security settings). A web server in front of Moodle can have its own limit, which only the server
 * administrator can read and which this function cannot see. Also reported: the user quota of the site, which Moodle
 * applies to private files and does not apply to the draft area the MiwLab sends to. Only settings of the site: no person.
 *
 * @package    local_miwlab
 * @copyright  2026 V3RTECH TECNOLOGIA, CONSULTORIA E INOVACAO LTDA <suporte@v3rtech.com.br>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_upload_limits extends base {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Returns the limits, the effective one and which setting it comes from.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG;
        $context = \context_system::instance();
        self::validate_context($context);
        // The same capability as the module types, the course formats and the blocks: all of them say only what the site has.
        require_capability('local/miwlab:viewmoduletypes', $context);
        return self::limits(
            (string) ini_get('upload_max_filesize'),
            (string) ini_get('post_max_size'),
            (int) $CFG->maxbytes,
            (int) ($CFG->userquota ?? 0)
        );
    }

    /**
     * The limits from the values of the settings, and the smallest one with its name (0 means no limit).
     *
     * @param string $uploadmax The PHP upload_max_filesize, as written in php.ini ("64M", "2G" or bytes).
     * @param string $postmax The PHP post_max_size.
     * @param int $sitemax The maximum upload size of the site (maxbytes); 0 is no limit.
     * @param int $userquota The user quota of the site; 0 is no limit.
     * @return array
     */
    public static function limits(string $uploadmax, string $postmax, int $sitemax, int $userquota): array {
        // As Moodle does (get_max_upload_file_size): an empty upload_max_filesize counts as 5M.
        $phpupload = (int) get_real_size($uploadmax === '' ? '5M' : $uploadmax);
        $phppost = (int) get_real_size($postmax);
        $effective = 0;
        $limitedby = 'none';
        // The order breaks a tie: the setting an administrator changes first is the one named.
        foreach (['site' => $sitemax, 'phpupload' => $phpupload, 'phppost' => $phppost] as $name => $bytes) {
            if ($bytes > 0 && ($effective === 0 || $bytes < $effective)) {
                $effective = $bytes;
                $limitedby = $name;
            }
        }
        return [
            'effective' => $effective,
            'limitedby' => $limitedby,
            'sitemaxbytes' => $sitemax,
            'phpuploadmaxfilesize' => $phpupload,
            'phppostmaxsize' => $phppost,
            'userquota' => $userquota,
        ];
    }

    /**
     * Returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'effective' => new external_value(PARAM_INT, 'Largest file the upload accepts, in bytes; 0 when nothing limits it'),
            'limitedby' => new external_value(PARAM_ALPHA, 'Which setting gives it: site, phpupload, phppost or none'),
            'sitemaxbytes' => new external_value(PARAM_INT, 'Maximum upload size of the site (maxbytes), in bytes; 0 is no limit'),
            'phpuploadmaxfilesize' => new external_value(PARAM_INT, 'PHP upload_max_filesize, in bytes'),
            'phppostmaxsize' => new external_value(PARAM_INT, 'PHP post_max_size, in bytes'),
            'userquota' => new external_value(PARAM_INT, 'User quota of the site (private files), in bytes; 0 is no limit'),
        ]);
    }
}
