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
 * A recordset like iterator for use with an Elasticsearch SQL query
 * that is limited by fetch_size and returns a cursor.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_elastic;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/aws/sdk/aws-autoloader.php');

/**
 * A recordset like iterator for use with an Elasticsearch SQL query
 * that is limited by fetch_size and returns a cursor.
 *
 * Must be constructed with the response body of an ES SQL query that has
 * been parsed with \logstore_elastic\elasticsearch::parse_sql_response().
 */
class esiterator extends \moodle_recordset {
    /** @var \logstore_elastic\elasticsearch $elasticsearch Client for interacting with elasticsearch. */
    protected $elasticsearch;

    /** @var \stdClass $query Object representing the elasticsearch query decoded from JSON. */
    protected $query;

    /** @var int $from The offset for the batch of records to retrieve from elasticsearch. */
    protected $from;

    /** @var int $size The number of records to retrieve from elasticsearch. */
    protected $size;

    /** @var array $rows An array of row objects. */
    protected $rows;

    /** @var array $current The current record available. */
    protected $current;

    /** @var int $key The key of the current record from this recordset. */
    protected $key;

    /**
     * Create a new esiterator instance.
     *
     * @param string $query JSON string representing the elasticsearch query.
     * @param integer $batchsize The number of records at a time to retrieve from elasticsearch and
     *                           keep in memory.
     */
    public function __construct(string $query, int $batchsize = 50) {
        $this->elasticsearch = new \logstore_elastic\elasticsearch();
        $this->rows  = [];
        // Initialise the key as -1 to indicate we have not yet made a query to elasticsearch.
        $this->key = -1;
        // Decode the query we have been given to an object to make it easier to work with.
        $this->query = json_decode($query);
        // Grab number of records required from the original query, we can use this later
        // to limit the number of records returned from the iterator.
        $this->size = $this->query->size;
        // Now we can set a fixed batch size for each query the iterator makes to elasticsearch.
        $this->query->size = $batchsize;
        // Similar to above, grab the initial "from" value from the original query.
        $this->from = $this->query->from ?? 0;
        // Go and fetch an initial batch of records from elasticsearch.
        $this->current = $this->fetch_next();
    }

    /**
     * Determines when to make a query to get a batch of records, returns the next available row
     * from the array of rows in memory.
     *
     * @return \stdClass|false Object representing a single row, false if next row isnt available.
     */
    private function fetch_next() {
        $hasrows = !!count($this->rows);
        $haskey = ($this->key != -1);
        // Do we need to as elasticsearch for some records? Will do this if we have no records to
        // iterate over and the key is less than the size parameter we extracted from the original
        // query.
        if (!$hasrows && $this->key < ($this->size - 1)) {
            // Update internal "from" pointer ready to retrieve next batch of results,
            // this needs to be 0 initially and then incremented after first batch.
            if ($haskey) {
                $this->from += $this->query->size;
            }
            // Add the "from" parameter to the query, or update it if it already exists.
            $this->query->from = $this->from;
            // Send the query to elasticsearch and parse the results.
            $parsedresponse = $this->elasticsearch->es_query(json_encode($this->query));
            $this->rows = $parsedresponse->rows;
        }
        // If we have no more available rows and do not have a cursor.
        if (!count($this->rows)) {
            return false;
        }
        // Get the next row to return and remove it from the array of rows in memory.
        $row = array_shift($this->rows);
        $this->key++;

        return $row;
    }

    /**
     * Returns current record.
     *
     * @return \stdClass
     */
    public function current(): \stdClass {
        return (object)$this->current;
    }

    /**
     * Returns the key of current row.
     *
     * @return integer
     */
    public function key(): int {
        return $this->key;
    }

    /**
     * Moves forward to next row.
     *
     * @return void
     */
    public function next(): void {
        $this->current = $this->fetch_next();
    }

    /**
     * Did we reach the end?
     *
     * @return boolean
     */
    public function valid(): bool {
        return !empty($this->current);
    }

    /**
     * Free resources and connections, recordset can not be used anymore.
     *
     * @return void
     */
    public function close() {
        return;
    }

}
