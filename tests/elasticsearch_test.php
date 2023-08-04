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
 * Elasticsearch log store tests.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace logstore_elastic;

defined('MOODLE_INTERNAL') || die();

global $CFG;

use \GuzzleHttp\Handler\MockHandler;
use \GuzzleHttp\HandlerStack;
use \GuzzleHttp\Middleware;
use \GuzzleHttp\Psr7\Response;

/**
 * tests for elasticsearch class.
 *
 * @package     logstore_elastic
 * @copyright   2023 Dale Davies <dale.davies@catalyst-eu.net>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class elasticsearch_test extends \advanced_testcase {

    /**
     * @var \stdClass $cfg Backup of global config.
     */
    protected $cfg;

    /**
     * Polyfill to support the new regexp assertion in place of the old, deprecated one.
     *
     * This can be removed once we no longer need to support Moodle <3.11/PHPUnit 8.5.
     *
     * @param string $method
     * @param array $args
     * @return mixed|void
     */
    public function __call(string $method, array $args) {
        if ($method === 'assertMatchesRegularExpression' && method_exists($this, 'assertRegExp')) {
            return call_user_func_array([$this, 'assertRegExp'], $args);
        }
    }

    /**
     * Test setup.
     */
    public function setUp(): void {
        global $CFG;
        $this->cfg = clone $CFG;
        $this->resetAfterTest(true);
        new \logstore_elastic\elasticsearch();
    }

    /**
     * Reset global $CFG object.
     *
     * @return void
     */
    public function tearDown(): void {
        global $CFG;
        $CFG = clone $this->cfg;
        unset($this->cfg);
    }

    /**
     * Test unsigned elasticsearch get functionality
     */
    public function test_get() {
        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->get($url);
        $request = $container[0]['request'];

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());

    }

    /**
     * Test signed elasticsearch get functionality
     */
    public function test_signed_get() {
        $this->resetAfterTest(true);
        set_config('signing', 1, 'logstore_elastic');
        set_config('signingkeyid', 'key_id', 'logstore_elastic');
        set_config('signingsecretkey', 'secret_key', 'logstore_elastic');
        set_config('region', 'region', 'logstore_elastic');

        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->get($url);
        $request = $container[0]['request'];
        $authheader = $request->getHeader('Authorization');

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('X-Amz-Date'));
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertMatchesRegularExpression('/key_id.{10}region/', $authheader[0]);
    }

    /**
     * Test unsigned elasticsearch put functionality
     */
    public function test_put() {
        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $params = '{"properties":"value"}';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->put($url, $params);
        $request = $container[0]['request'];
        $contentheader = $request->getHeader('content-type');

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertEquals(array('application/json'), $contentheader);

    }

    /**
     * Test signed elasticsearch put functionality
     */
    public function test_signed_put() {
        $this->resetAfterTest(true);
        set_config('signing', 1, 'logstore_elastic');
        set_config('signingkeyid', 'key_id', 'logstore_elastic');
        set_config('signingsecretkey', 'secret_key', 'logstore_elastic');
        set_config('region', 'region', 'logstore_elastic');

        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $params = '{"properties":"value"}';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->put($url, $params);
        $request = $container[0]['request'];
        $authheader = $request->getHeader('Authorization');
        $contentheader = $request->getHeader('content-type');

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('X-Amz-Date'));
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertMatchesRegularExpression('/key_id.{10}region/', $authheader[0]);
        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertEquals(array('application/json'), $contentheader);
    }

    /**
     * Test unsigned elasticsearch post functionality
     */
    public function test_post() {
        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $params = '{"properties":"value"}';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->post($url, $params);
        $request = $container[0]['request'];
        $contentheader = $request->getHeader('content-type');

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertEquals(array('application/json'), $contentheader);

    }

    /**
     * Test signed elasticsearch post functionality
     */
    public function test_signed_post() {
        $this->resetAfterTest(true);
        set_config('signing', 1, 'logstore_elastic');
        set_config('signingkeyid', 'key_id', 'logstore_elastic');
        set_config('signingsecretkey', 'secret_key', 'logstore_elastic');
        set_config('region', 'region', 'logstore_elastic');

        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://localhost:8080/foo?bar=blerg';
        $params = '{"properties":"value"}';
        $client = new \logstore_elastic\elasticsearch($stack);
        $response = $client->post($url, $params);
        $request = $container[0]['request'];
        $authheader = $request->getHeader('Authorization');
        $contentheader = $request->getHeader('content-type');

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('localhost', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertTrue($request->hasHeader('X-Amz-Date'));
        $this->assertTrue($request->hasHeader('Authorization'));
        $this->assertMatchesRegularExpression('/key_id.{10}region/', $authheader[0]);
        $this->assertTrue($request->hasHeader('content-type'));
        $this->assertEquals(array('application/json'), $contentheader);
    }

    /**
     * Test elasticsearch get with proxy functionality
     */
    public function test_proxy_get() {
        global $CFG;
        $CFG->proxyhost = 'proxy.com';
        $CFG->proxyport = 3128;
        $CFG->proxybypass = 'localhost, 127.0.0.1';

        $container = [];
        $history = Middleware::history($container);

        // Create a mock and queue two responses.
        $mock = new MockHandler([
                new Response(200, ['Content-Type' => 'text/plain'])
        ]);

        $stack = HandlerStack::create($mock);
        // Add the history middleware to the handler stack.
        $stack->push($history);

        $url = 'http://example.com:8080/foo?bar=blerg';
        $client = new \logstore_elastic\elasticsearch($stack);
        $client->get($url);
        $request = $container[0]['request'];

        $lastrequestoptions = $mock->getLastOptions();
        $this->assertArrayHasKey('proxy', $lastrequestoptions);
        $expected = 'proxy.com:3128';

        // Check the results.
        $this->assertEquals('http', $request->getUri()->getScheme());
        $this->assertEquals('example.com', $request->getUri()->getHost());
        $this->assertEquals('8080', $request->getUri()->getPort());
        $this->assertEquals('/foo', $request->getUri()->getPath());
        $this->assertEquals('bar=blerg', $request->getUri()->getQuery());
        $this->assertEquals($expected, $lastrequestoptions['proxy']);
    }

}
