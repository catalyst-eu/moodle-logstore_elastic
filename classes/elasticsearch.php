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
 * Class for interacting with Elasticsearch as a log store.
 *
 * The signrequest(), http_action(), get(), put() and post() methods are
 * adapted from the esrequest class bundled in moodle-search_elastic...
 *
 * https://github.com/catalyst/moodle-search_elastic
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_elastic;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/aws/sdk/aws-autoloader.php');

class elasticsearch {
    /** @var bool True if we should sign requests, false if not */
    private $signing = false;

    /** @var object $config logstore_elastic Plugin config */
    private $config = null;

    /** @var \GuzzleHttp\Client $client A guzzle client */
    private $client;

    /**
     * @var array Array of array of field names and types requered for an Elasticsearch
     * index to be used for storing and retrieving event data.
     */
    private $requiredfields = [
        "action" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "acutime" => [
            "type" => "long"
        ],
        "anonymous" => [
            "type" => "long"
        ],
        "component" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "contextid" => [
            "type" => "long"
        ],
        "contextinstanceid" => [
            "type" => "long"
        ],
        "contextlevel" => [
            "type" => "long"
        ],
        "courseid" => [
            "type" => "long"
        ],
        "crud" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "edulevel" => [
            "type" => "long"
        ],
        "eventname" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "ip" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "objectid" => [
            "type" => "integer"
        ],
        "objecttable" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "origin" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "other" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "realuserid" => [
            "type" => "long"
        ],
        "relateduserid" => [
            "type" => "integer"
        ],
        "target" => [
            "type" => "text",
            "fields" => [
                "raw" => [
                    "type" => "keyword"
                ]
            ]
        ],
        "timecreated" => [
            "type" => "long"
        ],
        "userid" => [
            "type" => "integer"
        ]
    ];

    /**
     * Initialises the search engine configuration.
     *
     * Search engine availability should be checked separately.
     *
     * @param \GuzzleHttp\HandlerStack $handler Optional custom Guzzle handler stack
     * @return void
     */
    public function __construct(\GuzzleHttp\HandlerStack $handler = null) {
        $this->config = get_config('logstore_elastic');
        $this->signing = (isset($this->config->signing) ? (bool)$this->config->signing : false);
        // Allow the caller to instantiate the Guzzle client with a custom handler.
        $clientconfig = [];
        if ($handler) {
            $clientconfig['handler'] = $handler;
        }
        $this->client = \local_aws\local\guzzle_helper::configure_client_proxy(new \GuzzleHttp\Client($clientconfig));
    }

    /**
     * Get config values available to class when instantiated.
     *
     * @return \stdClass
     */
    public function get_config(): \stdClass {
        return $this->config;
    }

    /**
     * Return an array of field names and types requered for an Elasticsearch
     * index to be used for storing event data.
     *
     * @return array
     */
    public function get_required_fields(): array {
        return $this->requiredfields;
    }

    /**
     * Generates the Elasticsearch server endpoint URL from
     * the config hostname and port.
     *
     * @return string|bool Returns url if succes or false on error.
     */
    private function get_url() {
        if (!empty($this->config->hostname) && !empty($this->config->port)) {
            $url = rtrim($this->config->hostname, "/");
            $port = $this->config->port;
            return $url . ':'. $port;
        }

        return false;
    }

    /**
     * Signs a request with the supplied credentials.
     * This is used for access control to the Elasticsearch endpoint.
     *
     * @param \GuzzleHttp\Psr7\Request $request
     * @throws \moodle_exception
     * @return \GuzzleHttp\Psr7\Request
     */
    private function signrequest(\GuzzleHttp\Psr7\Request $request): \GuzzleHttp\Psr7\Request {
        // Check we are all configured for request signing.
        if (empty($this->config->signingkeyid) ||
                empty($this->config->signingsecretkey) ||
                empty($this->config->region)) {
            throw new \moodle_exception('noconfig', 'logstore_elastic', '');
        }
        // Pull credentials from the default provider chain.
        $credentials = new \Aws\Credentials\Credentials(
                $this->config->signingkeyid,
                $this->config->signingsecretkey
                );
        // Create a signer with the service's signing name and region.
        $signer = new \Aws\Signature\SignatureV4('es', $this->config->region);
        // Sign your request.
        $signedrequest = $signer->signRequest($request, $credentials);

        return $signedrequest;
    }

    /**
     * Execute the HTTP action and return the response.
     * Requests that receive a 4xx or 5xx response will throw a
     * Guzzle\Http\Exception\BadResponseException.
     * Requests to a URL that does not resolve will raise a \GuzzleHttp\Exception\GuzzleException.
     * We want to handle this in a sane way and provide the caller with
     * a useful response. So we catch the error and return the
     * response.
     *
     * @param \GuzzleHttp\Psr7\Request $psr7request
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function http_action(\GuzzleHttp\Psr7\Request $psr7request): \GuzzleHttp\Psr7\Response {
        try {
            $response = $this->client->send($psr7request);
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $response = $e->getResponse();
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            // This case does not provide a response object with a consistent interface so we need
            // to make one.
            $response = new \logstore_elastic\guzzle_exception();
        }

        return $response;
    }

    /**
     * Process GET requests to Elasticsearch.
     *
     * @param string $url
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function get(string $url): \Psr\Http\Message\ResponseInterface {
        $psr7request = new \GuzzleHttp\Psr7\Request('GET', $url);
        if ($this->signing) {
            $psr7request = $this->signrequest($psr7request);
        }
        $response = $this->http_action($psr7request);

        return $response;
    }

    /**
     * Process PUT requests to Elasticsearch.
     *
     * @param string $url
     * @param string $payload
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function put(string $url, string $payload = null): \Psr\Http\Message\ResponseInterface {
        $headers = ['content-type' => 'application/json'];
        $psr7request = new \GuzzleHttp\Psr7\Request('PUT', $url, $headers, $payload);
        if ($this->signing) {
            $psr7request = $this->signrequest($psr7request);
        }
        $response = $this->http_action($psr7request);

        return $response;
    }

    /**
     * Creates post API requests.
     *
     * @param string $url
     * @param string $payload
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function post(string $url, string $payload): \Psr\Http\Message\ResponseInterface {
        $headers = ['content-type' => 'application/json'];
        $psr7request = new \GuzzleHttp\Psr7\Request('POST', $url, $headers, $payload);
        if ($this->signing) {
            $psr7request = $this->signrequest($psr7request);
        }
        $response = $this->http_action($psr7request);

        return $response;
    }

    /**
     * Is the Elasticsearch server endpoint configured in Moodle
     * and available.

     * @return bool True on success False on failure
     */
    public function is_server_ready(): bool {
        $url = $this->get_url();
        if (!$url) {
            return false;
        } else {
            $response = $this->get($url);
            $responsecode = $response->getStatusCode();
        }
        if ($responsecode != 200) {
            return false;
        }

        return true;
    }

    /**
     * Check if index exists in Elasticssearch backend
     *
     * @return bool True on success False on failure
     */
    public function check_index(): bool {
        $url = $this->get_url();
        if (!empty($this->config->index) && $url) {
            $index = $url . '/'. $this->config->index;
            $response = $this->get($index);
            $responsecode = $response->getStatusCode();
        }
        if ($responsecode != 200) {
            return false;
        }

        return true;
    }

    /**
     * Check if the elasticsearch index is valid.
     *
     * @return boolean $valid If the index is valid.
     */
    public function validate_index(): bool {
        // Get existing index definition.
        $url = $this->get_url();
        $indexeurl = $url . '/'. $this->config->index. '/_mapping';
        $response = $this->get($indexeurl);
        // If the mapping doesn't exist then create it based on a valid mapping.
        if ($response->getStatusCode() == '404') {
            $this->create_index();
            return true;
        }
        // If the mapping does exist then validate it.
        $responsebody = json_decode($response->getBody());
        $indexfields = $responsebody->{$this->config->index}->mappings->properties;
        // Iterate through required fields and compare to index.
        foreach ($this->requiredfields as $name => $field) {
            if (!isset($indexfields->{$name}->type)) {
                return false;
            }
            if ($indexfields->{$name}->type != $field['type']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Create index with mapping in Elasticsearch backend.
     *
     * @return void
     */
    public function create_index(): void {
        $url = $this->get_url();
        if (!empty($this->config->index) && $url) {
            $indexurl = $url . '/'. $this->config->index;
            $mapping = ['mappings' => ['properties' => $this->requiredfields]];
            $response = $this->put($indexurl, json_encode($mapping));
            $responsecode = $response->getStatusCode();
        } else {
            throw new \moodle_exception('noconfig', 'logstore_elastic', '');
        }
        if ($responsecode !== 200) {
            throw new \moodle_exception('indexfail', 'logstore_elastic', '');
        }
    }

    /**
     * Perform indexing of one or more documents in ELasticsearch.
     *
     * @param array $documents
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function create_docs(array $documents): \Psr\Http\Message\ResponseInterface {
        $url = $this->get_url();
        $bulkurl = $url . '/_bulk/';
        $payload = '';
        foreach ($documents as $doc) {
            $payload .= '{ "create" : { "_index" : "'.$this->config->index.'"} }'."\n";
            $payload .= json_encode($doc)."\n";
        }
        return $this->post($bulkurl, $payload);
    }

    /**
     * Creates delete API requests.
     *
     * @param string $query
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function delete_by_query(string $query): \Psr\Http\Message\ResponseInterface {
        $url = $this->get_url() . '/' . $this->config->index . '/_delete_by_query';

        return $this->post($url, $query);
    }

    /**
     * Translates a SQL query via the elasticsearch translate endpoint, into an object suitable
     * for use with elasticsearch.
     *
     * @param string $sql Parametarised SQL query.
     * @param array $params Any SQL parameters to be used.
     * @return \stdClass
     */
    public function es_translate_sql(string $sql, array $params): \stdClass {
        $url = $this->get_url() . '/_sql/translate';
        $esparams = json_encode(['query' => $sql, 'params' => $params]);
        $response = $this->post($url, $esparams);
        return json_decode($response->getBody());
    }

    /**
     * Takes the component SQL parts and parameters passed from Moodle to query the logs and
     * translates them into a JSON string suitable for use as an elasticsearch query using
     * es_query().
     *
     * @param string $selectwhere
     * @param array $params
     * @param string $sort
     * @param int $limitfrom
     * @param int $limitnum
     * @return string JSON string for use with es_query().
     */
    public function prepare_es_query(string $selectwhere = null, array $params, string $sort = null, int $limitfrom = null, int $limitnum = null): string {
        // Concat sql together.
        $sql = 'SELECT * FROM '.$this->config->index;
        if ($selectwhere) {
            $sql .= ' WHERE '.$selectwhere;
        }
        // Add sort into sql.
        if ($sort) {
            $sql .= " ORDER BY $sort";
        }
        // Add limit and offset to sql.
        if ($limitnum) {
            $sql .= " LIMIT $limitnum";
        }
        // Convert SQL from named params to positional, checks params exist etc.
        $db = \moodle_database::get_driver_instance('mysqli', 'native');
        list($sql, $params, $type) = $db->fix_sql_params($sql, $params);
        // Send the SQL query to elasticsearch translate api, this will give us a proper elasticsearch
        // query to use with elasticsearch.
        $body = $this->es_translate_sql($sql, $params);
        // Add limitfrom value to elasticsearch query, ultimately we need to do this because we can't
        // use OFFSET in an ES SQL query.
        if ($limitfrom) {
            $body->from = $limitfrom;
        }
        // Set _source option to retrieve the original data that was passed at index time.
        $body->_source = true;

        return json_encode($body);
    }

    /**
     * Performs a search in elasticsearch using the provided query string, parses the results and
     * return all of them in an stdClass object.
     *
     * @param string $query
     * @return \stdClass
     */
    public function es_query(string $query): \stdClass {
        // Send the query to Elasticsearch.
        $url = $this->get_url().'/'.$this->config->index.'/_search';
        $response = $this->post($url, $query);

        return $this->parse_response(json_decode($response->getBody()));
    }

    /**
     * Like es_query() but returns a recordset (esiterator) object.
     *
     * @param string $query
     * @return esiterator
     */
    public function es_query_recordset(string $query): esiterator {
        // Send the query to Elasticsearch but use an esiterator to limit the number of
        // records in memory at any time time like an actual recordset.
        return new esiterator($query);
    }

    /**
     * Parse the results of an elasticsearch search query into an object.
     *
     * @param \stdClass $response
     * @return \stdClass
     */
    public function parse_response(\stdClass $response): \stdClass {
        $parsedresponse = new \stdClass();
        if (isset($response->hits->hits)) {
            $parsedresponse->rows = [];
            foreach ($response->hits->hits as $hit) {
                $result = new \stdClass();
                foreach ((array) $hit->_source as $field => $val) {
                    $result->$field = $val;
                }
                $parsedresponse->rows[] = $result;
            }
        }
        if (isset($response->hits->total)) {
            $parsedresponse->count = $response->hits->total->value;
        }

        return $parsedresponse;
    }
}
