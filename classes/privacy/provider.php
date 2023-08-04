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
 * Privacy provider class for Elasticsearch log store.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_elastic\privacy;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use tool_log\local\privacy\helper;

class provider implements
        // This plugin does store personal user data.
        \core_privacy\local\metadata\provider,
        \tool_log\local\privacy\logstore_provider,
        \tool_log\local\privacy\logstore_userlist_provider {


    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link(
            'elasticsearch',
            [
                'anonymous' => 'privacy:metadata:elasticsearch:anonymous',
                'eventname' => 'privacy:metadata:elasticsearch:eventname',
                'ip' => 'privacy:metadata:elasticsearch:ip',
                'origin' => 'privacy:metadata:elasticsearch:origin',
                'other' => 'privacy:metadata:elasticsearch:other',
                'realuserid' => 'privacy:metadata:log:realuserid',
                'relateduserid' => 'privacy:metadata:elasticsearch:relateduserid',
                'timecreated' => 'privacy:metadata:elasticsearch:timecreated',
                'userid' => 'privacy:metadata:elasticsearch:userid',
            ],
            'privacy:metadata:elasticsearch'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   int           $userid       The user to search.
     * @return  contextlist   $contextlist  The list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        $contextlist = new \core_privacy\local\request\contextlist();
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $query = $elasticsearch->prepare_es_query('userid = ? OR realuserid = ? OR relateduserid = ?',  [$userid, $userid, $userid]);
        $response = $elasticsearch->es_query_recordset($query);
        $sql = [];
        $params = [];
        foreach ($response as $record) {
            $sql[] = '?';
            $params[] = $record->contextid;
        }
        $contextlist->add_from_sql(implode(',', $sql), $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $query = $elasticsearch->prepare_es_query('contextid = ?',  [$context->id]);
        $response = $elasticsearch->es_query_recordset($query);
        $userids = [];
        foreach ($response as $record) {
            if (!is_null($record->userid)) {
                $userids[] = $record->userid;
            }
            if (!is_null($record->realuser)) {
                $userids[] = $record->realuser;
            }
            if (!is_null($record->relateduserid)) {
                $userids[] = $record->relateduserid;
            }
        }
        $userlist->add_users(array_unique($userids));
    }

    /**
     * Add contexts that contain user information for the specified user.
     *
     * @param contextlist $contextlist The contextlist to add the contexts to.
     * @param int $userid The user to find the contexts for.
     * @return void
     */
    public static function add_contexts_for_userid(contextlist $contextlist, $userid) {
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $query = $elasticsearch->prepare_es_query('userid = ? OR realuserid = ? OR relateduserid = ?',  [$userid, $userid, $userid]);
        $response = $elasticsearch->es_query_recordset($query);
        $sql = [];
        $params = [];
        foreach ($response as $record) {
            $sql[] = '?';
            $params[] = $record->contextid;
        }
        $contextlist->add_from_sql(implode(',', $sql), $params);
    }

    /**
     * Add user IDs that contain user information for the specified context.
     *
     * @param \core_privacy\local\request\userlist $userlist The userlist to add the users to.
     * @return void
     */
    public static function add_userids_for_context(\core_privacy\local\request\userlist $userlist) {
        $context = $userlist->get_context();
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $query = $elasticsearch->prepare_es_query('contextid = ?',  [$context->id]);
        $response = $elasticsearch->es_query_recordset($query);
        $userids = [];
        foreach ($response as $record) {
            if (!is_null($record->userid)) {
                $userids[] = $record->userid;
            }
            if (!is_null($record->realuser)) {
                $userids[] = $record->realuser;
            }
            if (!is_null($record->relateduserid)) {
                $userids[] = $record->relateduserid;
            }
        }
        $userlist->add_users(array_unique($userids));
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * This has been adapted from \tool_log\local\privacy\moodle_database_export_and_delete::export_user_data().
     *
     * @param approved_contextlist $contextlist The approved contexts to export information for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        $elasticsearch = new \logstore_elastic\elasticsearch();
        // Ordering by contextid is important as it will help us group records for export later.
        $query = $elasticsearch->prepare_es_query('userid = ? OR realuserid = ? OR relateduserid = ?',  [$userid, $userid, $userid], 'contextid, timecreated, acutime');
        $response = $elasticsearch->es_query_recordset($query);
        $path = [get_string('privacy:path:logs', 'tool_log'), get_string('pluginname', 'logstore_elastic')];
        // Closure used for flushing data grouped by context to the writer.
        $flush = function($lastcontextid, $data) use ($path) {
            $context = \context::instance_by_id($lastcontextid);
            writer::with_context($context)->export_data($path, (object) ['logs' => $data]);
        };
        $lastcontextid = null;
        $data = [];
        // Group records for export by context, only save send them to the writer when we
        // reach the next contextid.
        foreach ($response as $record) {
            if ($lastcontextid && $lastcontextid != $record->contextid) {
                $flush($lastcontextid, $data);
                $data = [];
            }
            $data[] = helper::transform_standard_log_record_for_userid($record, $userid);
            $lastcontextid = $record->contextid;
        }
        if ($lastcontextid) {
            $flush($lastcontextid, $data);
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context The specific context to delete data for.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $elasticsearch->delete_by_query('{
            "query": {
                "match": {
                    "contextid": "'.$context->id.'"
                }
            }
        }');
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete information for.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = $contextlist->get_user()->id;
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $elasticsearch->delete_by_query('{
            "query": {
                "bool": {
                    "should": [
                        {
                            "term": {
                                "userid": {
                                    "value": '.$userid.'
                                }
                            }
                        },
                        {
                            "term": {
                                "realuserid": {
                                    "value": '.$userid.'
                                }
                            }
                        },
                        {
                            "term": {
                                "relateduserid": {
                                    "value": '.$userid.'
                                }
                            }
                        }
                    ]
                }
            }
        }');
    }

    /**
     * Delete all data for a list of users in the specified context.
     *
     * @param \core_privacy\local\request\approved_userlist $userlist The specific context and users to delete data for.
     * @return void
     */
    public static function delete_data_for_userlist(\core_privacy\local\request\approved_userlist $userlist) {
        $userids = json_encode($userlist->get_userids());
        $elasticsearch = new \logstore_elastic\elasticsearch();
        $elasticsearch->delete_by_query('{
            "query": {
                "bool": {
                    "should": [
                        {
                            "terms": {
                                "userid": '.$userids.'
                            }
                        },
                        {
                            "terms": {
                                "realuserid" '.$userids.'
                            }
                        },
                        {
                            "terms": {
                                "relateduserid":  '.$userids.'
                            }
                        }
                    ]
                }
            }
        }');
    }

}
