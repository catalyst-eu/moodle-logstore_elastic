<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Elasticsearcg log store settings.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // Perform connection checks but only if viewing the settings page.
    if ($PAGE->pagetype === 'admin-setting-logsettingelastic') {
        $config = get_config('logstore_elastic');
        // Can't check anything if the hostname has not been configured.
        if (!empty($config->hostname)) {
            $elasticsearch = new logstore_elastic\elasticsearch();
            // Check if we can connect to the server, if we can then check if the index name is configured.
            if (!$elasticsearch->is_server_ready()) {
                $notify = new \core\output\notification(get_string('notification:cantconnect', 'logstore_elastic'),
                \core\output\notification::NOTIFY_ERROR);
                $notify->set_show_closebutton(false);
                $settings->add(new admin_setting_heading('connectionissue', '', $OUTPUT->render($notify)));
            } else if (empty($elasticsearch->get_config()->index)) {
                $notify = new \core\output\notification(get_string('notification:noindex', 'logstore_elastic'),
                \core\output\notification::NOTIFY_ERROR);
                $notify->set_show_closebutton(false);
                $settings->add(new admin_setting_heading('connectionissue', '', $OUTPUT->render($notify)));
            }
        }
    }

    $settings->add(new admin_setting_heading('logstoresettings', get_string('logstoresettings', 'logstore_elastic'), ''));

    $settings->add(new admin_setting_configcheckbox('logstore_elastic/logguests',
        new lang_string('logguests', 'core_admin'),
        new lang_string('logguests_help', 'core_admin'), 1));

    $settings->add(new admin_setting_configcheckbox('logstore_elastic/jsonformat',
            new lang_string('jsonformat', 'logstore_elastic'),
            new lang_string('jsonformat_desc', 'logstore_elastic'), 1));

    $settings->add(new admin_setting_heading('basicsettings', get_string('basicsettings', 'logstore_elastic'), ''));

    $settings->add(new admin_setting_configtext('logstore_elastic/hostname', get_string ('hostname', 'logstore_elastic'),
        get_string ('hostname_help', 'logstore_elastic'), null, PARAM_URL));

    $settings->add(new admin_setting_configtext('logstore_elastic/port', get_string ('port', 'logstore_elastic'),
        get_string ('port_help', 'logstore_elastic'), 9200, PARAM_INT));

    $settings->add(new admin_setting_configtext('logstore_elastic/index', get_string ('index', 'logstore_elastic'),
        get_string ('index_help', 'logstore_elastic'), null, PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_configtext('logstore_elastic/sendsize', get_string ('sendsize', 'logstore_elastic'),
        get_string ('sendsize_help', 'logstore_elastic'), 9000000, PARAM_ALPHANUMEXT));

    $settings->add(new admin_setting_heading('signingsettings', get_string('signingsettings', 'logstore_elastic'), get_string('signingsettings_help', 'logstore_elastic')));
    $settings->add(new admin_setting_configcheckbox('logstore_elastic/signing', get_string('signing', 'logstore_elastic'),
        get_string ('signing_help', 'logstore_elastic'), 0));

    $settings->add(new admin_setting_configtext('logstore_elastic/signingkeyid', get_string ('signingkeyid', 'logstore_elastic'),
        get_string ('signingkeyid_help', 'logstore_elastic'), '', PARAM_TEXT));

    $settings->add(new admin_setting_configpasswordunmask('logstore_elastic/signingsecretkey',
        get_string ('signingsecretkey', 'logstore_elastic'),
        get_string ('signingsecretkey_help', 'logstore_elastic'), ''));

    $settings->add(new admin_setting_configtext('logstore_elastic/region', get_string ('region', 'logstore_elastic'),
        get_string ('region_help', 'logstore_elastic'), 'us-west-2', PARAM_TEXT));
}
