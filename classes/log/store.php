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
 * Elastic search log reader/writer.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_elastic\log;

defined('MOODLE_INTERNAL') || die();

class store implements \tool_log\log\writer, \core\log\sql_reader {
    use \tool_log\helper\store,
        \tool_log\helper\buffered_writer,
        \tool_log\helper\reader;

    /** @var string $logguests true if logging guest access */
    protected $logguests;

    /** @var \logstore_elastic\elasticsearch $elasticsearch */
    protected $elasticsearch;

    public function __construct(\tool_log\log\manager $manager) {
        $this->helper_setup($manager);
        // Log everything before setting is saved for the first time.
        $this->logguests = (bool) $this->get_config('logguests', true);
        // Note: This variable is defined in the buffered_writer trait.
        $this->jsonformat = (bool) $this->get_config('jsonformat', true);
        $this->elasticsearch = new \logstore_elastic\elasticsearch();
    }

    /**
     * Check if Elastic search is ready to be used.
     *
     * @return bool
     */
    public function is_elasticsearch_ready(): bool {
        if ($this->elasticsearch->is_server_ready() === false) {
            return false;
        }
        if (empty($this->elasticsearch->get_config()->index)) {
            return false;
        }
        if (!$this->elasticsearch->validate_index()) {
            return false;
        }

        return true;
    }

    /**
     * Should the event be ignored (not logged)?
     * @param \core\event\base $event
     * @return bool
     */
    protected function is_event_ignored(\core\event\base $event): bool {
        if ((!CLI_SCRIPT || PHPUNIT_TEST) && !$this->logguests) {
            // Always log inside CLI scripts because we do not login there.
            if (!isloggedin() || isguestuser()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store the events in Elasticsearch DB.
     *
     * We cannot have an auto-incrementing ID field in ES, which is used elsewhere
     * to ensure the order of events is returned correctly, so we add a more accurate
     * timestamp to the record before inserting it.
     *
     * @param array $evententries raw event data
     * @return void
     */
    protected function insert_event_entries($evententries): void {
        // We may have multiple events to insert.
        foreach ($evententries as $key => $entry) {
            // The event may not contain all required fields so create a new array containing
            // keys for all required fields and populate it with values from the event.
            $doc = [];
            foreach (array_keys($this->elasticsearch->get_required_fields()) as $field) {
                $doc[$field] = $entry[$field] ?? null;
            }
            $doc['acutime'] = floor(microtime(true) * 1000);
            // Replace the original event array with our new one.
            $evententries[$key] = $doc;
        }
        $this->elasticsearch->create_docs($evententries);
    }

    /**
     * Returns an event from the log data.
     *
     * @param stdClass $data Log data
     * @return \core\event\base
     */
    public function get_log_event($data): \core\event\base {
        // We need an array to pass to core\event\base::restore().
        $data = (array)$data;
        // Decode any "other" data.
        $data['other'] = self::decode_other($data['other']);
        if ($data['other'] === false) {
            $data['other'] = array();
        }
        // Some data stored in ES should be added to the restored
        // event as extra data.
        $extra = array('origin' => $data['origin'], 'ip' => $data['ip']);
        if (isset($data['realuserid']) || is_null($data['realuserid'])) {
            $extra['realuserid'] = $data['realuserid'];
            unset($data['realuserid']);
        }
        // Unset fields that are not used by event class.
        unset($data['origin']);
        unset($data['ip']);
        unset($data['id']);
        unset($data['acutime']);
        // Restore a proper event object from our data.
        if (!$event = \core\event\base::restore($data, $extra)) {
            return null;
        }

        return $event;
    }

    /**
     * Are the new events able to be logged?
     *
     * @return bool true means new log events can be added, false means no new data can be added.
     */
    public function is_logging(): bool {
        if (!$this->is_elasticsearch_ready()) {
            return false;
        }

        return true;
    }

    /**
     * Adds acutime column to $sort to ensure events from requests within one second
     * of each other are returned in the correct order.
     *
     * We need this because unix timestamps (e.g. timecreated) are only accurate to
     * one second, normally events stored in a relational DB would have an ID field
     * that could be used to recreate the order records were added.
     *
     * @param string $sort
     * @return string sort string
     */
    protected function tweak_sort_by_acutime(string $sort): string {
        if (empty($sort)) {
            $sort = "acutime ASC";
        } else if (stripos($sort, 'timecreated') === false) {
            $sort .= ", acutime ASC";
        } else if (stripos($sort, 'timecreated DESC') !== false) {
            $sort .= ", acutime DESC";
        } else {
            $sort .= ", acutime ASC";
        }

        return $sort;
    }

    /**
     * Get an array of events based on the passed on params.
     *
     * @param string $selectwhere select conditions.
     * @param array $params params.
     * @param string $sort sortorder.
     * @param int $limitfrom limit constraints.
     * @param int $limitnum limit constraints.
     *
     * @return array|\core\event\base[] array of events.
     */
    public function get_events_select($selectwhere, array $params, $sort, $limitfrom, $limitnum) {
        if (!$this->is_elasticsearch_ready()) {
            return [];
        }
        // Fix the SQL sort string to include acutime.
        $sort = $this->tweak_sort_by_acutime($sort);
        // Send the query to Elasticsearch and return an array of \core\event\base objects.
        $events = [];
        $query = $this->elasticsearch->prepare_es_query($selectwhere, $params, $sort, (int) $limitfrom, (int) $limitnum);
        $response = $this->elasticsearch->es_query($query);
        foreach ($response->rows as $data) {
            if ($event = $this->get_log_event($data)) {
                $events[$data->acutime] = $event;
            }
        }

        return $events;
    }

    /**
     * Fetch records using given criteria returning a Traversable object.
     *
     * @param string $selectwhere
     * @param array $params
     * @param string $sort
     * @param int $limitfrom
     * @param int $limitnum
     * @return \core\dml\recordset_walk
     */
    public function get_events_select_iterator($selectwhere, array $params, $sort, $limitfrom, $limitnum): \core\dml\recordset_walk {
        if (!$this->is_elasticsearch_ready()) {
            return [];
        }
        // Fix the SQL sort string to include acutime.
        $sort = $this->tweak_sort_by_acutime($sort);
        // Send the query to Elasticsearch, we will get an iterator back.
        $query = $this->elasticsearch->prepare_es_query($selectwhere, $params, $sort, (int) $limitfrom, (int) $limitnum);
        $recordset = $this->elasticsearch->es_query_recordset($query);

        // Walk the recordset and convert each item into a \core\event\base object.
        return new \core\dml\recordset_walk($recordset, array($this, 'get_log_event'));
    }


    /**
     * Get number of events present for the given select clause.
     *
     * @param string $selectwhere select conditions.
     * @param array $params params.
     *
     * @return int Number of events available for the given conditions
     */
    public function get_events_select_count($selectwhere, array $params): int {
        if (!$this->is_elasticsearch_ready()) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM '.$this->get_config('index');
        if ($selectwhere) {
            $sql .= ' WHERE '.$selectwhere;
        }
        // Convert sql from named params to positional, checks params exist etc.
        $db = \moodle_database::get_driver_instance('mysqli', 'native');
        list($sql, $params, $type) = $db->fix_sql_params($sql, $params);
        $query = $this->elasticsearch->es_translate_sql($sql, $params);
        $response = $this->elasticsearch->es_query(json_encode($query));

        return $response->count;
    }

    /**
     * Get whether events are present for the given select clause.
     *
     * @param string $selectwhere select conditions.
     * @param array $params params.
     *
     * @return bool Whether events available for the given conditions
     */
    public function get_events_select_exists(string $selectwhere, array $params): bool {
        if (!$this->is_elasticsearch_ready()) {
            return false;
        }

        return (bool) $this->get_events_select_count($selectwhere, $params);
    }

}
