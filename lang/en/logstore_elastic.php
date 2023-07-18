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
 * Elasticsearch log store language strings.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
$string['pluginname'] = 'Elasticsearch log';
$string['pluginname_desc'] = 'A log plugin stores log entries in Elasticsearch.';

$string['basicsettings'] = 'Elasticsearch: Basic settings';
$string['hostname'] = 'Hostname';
$string['hostname_help'] = 'The FQDN of the Elasticsearch engine endpoint (e.g. http://127.0.0.1)';
$string['index'] = 'Index';
$string['index_help'] = 'Namespace index to store search data in backend (e.g. moodlelogstore)';

$string['jsonformat'] = 'JSON format';
$string['jsonformat_desc'] = 'Use standard JSON format instead of PHP serialised data in the \'other\' database field.';

$string['logstoresettings'] = 'Log store settings';

$string['noconfig'] = 'Elasticsearch configuration missing';
$string['noserver'] = 'Elasticsearch endpoint unreachable';

$string['notification:cantconnect'] = 'Unable to connect to Elasticsearch!';
$string['notification:noindex'] = 'Index is not configured, cannot create log.';

$string['port'] = 'Port';
$string['port_help'] = 'The Port of the Elasticsearch engine endpoint';

$string['privacy:metadata:elasticsearch'] = 'Some event data is sent to a remote Elasticsearch database.';
$string['privacy:metadata:elasticsearch:anonymous'] = 'Whether the event was flagged as anonymous';
$string['privacy:metadata:elasticsearch:eventname'] = 'The event name';
$string['privacy:metadata:elasticsearch:ip'] = 'The IP address used at the time of the event';
$string['privacy:metadata:elasticsearch:origin'] = 'The origin of the event';
$string['privacy:metadata:elasticsearch:other'] = 'Additional information about the event';
$string['privacy:metadata:elasticsearch:realuserid'] = 'The ID of the real user behind the event, when masquerading a user.';
$string['privacy:metadata:elasticsearch:relateduserid'] = 'The ID of a user related to this event';
$string['privacy:metadata:elasticsearch:timecreated'] = 'The time when the event occurred';
$string['privacy:metadata:elasticsearch:userid'] = 'The ID of the user who triggered this event';

$string['region'] = 'Region';
$string['region_help'] = 'The AWS region the Elasticsearch instance is in, e.g. ap-southeast-2';

$string['sendsize'] = 'Request size';
$string['sendsize_help'] = 'Some Elasticsearch providers such as AWS have a limit on how big the HTTP payload can be. Therefore we limit it to a size in bytes.';
$string['signing'] = 'Enable request signing';
$string['signing_help'] = 'When enabled Moodle will sign each request to Elasticsearch with the credentials below';
$string['signingkeyid'] = 'Key ID';
$string['signingkeyid_help'] = 'The ID of the key to use for signing requests.';
$string['signingsecretkey'] = 'Secret Key';
$string['signingsecretkey_help'] = 'The secret key to use to sign requests.';
$string['signingsettings'] = 'Elasticsearch: Request signing settings';
$string['signingsettings_help'] = 'This generally only applies if you are using Amazon Web Service (AWS) to provide your Elasticsearch Endpoint.';
