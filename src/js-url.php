<?php
/**
 * JavaScript-backed URL implementation for PHP.
 *
 * This library delegates all URL parsing and manipulation to the
 * JavaScript `URL` and `URLSearchParams` classes by sending string-based
 * messages through a `js()` bridge function. The bridge function must be
 * provided by the host environment and should synchronously return a
 * string response for each message.
 *
 * The protocol between PHP and JavaScript is JSON encoded. Each message
 * is a JSON string describing the operation to perform and any
 * arguments. The JavaScript side is responsible for maintaining a map of
 * identifiers to actual objects.
 *
 * Example message payloads from PHP to JS:
 *
 * `{ "target": "URL", "op": "new", "id": 1, "url": "https://example.com" }`
 * `{ "target": "URL", "op": "get", "id": 1, "prop": "href" }`
 * `{ "target": "URL", "op": "set", "id": 1, "prop": "hash", "value": "#frag" }`
 * `{ "target": "URLSearchParams", "op": "call", "id": 2, "method": "get", "args": ["foo"] }`
 *
 * The JavaScript side should respond with JSON encoded strings or plain
 * strings depending on the operation. For property getters that return
 * `URLSearchParams`, the JS handler should allocate a new identifier for
 * the returned object and respond with that numeric identifier.
 *
 * This file intentionally avoids modern PHP features so that it remains
 * compatible with PHP 5.6 and later.
 */

if ( ! function_exists( 'js' ) ) {
/**
 * Send a message to the JavaScript runtime and synchronously return a string.
 *
 * This function is a placeholder. The host environment must provide a
 * concrete implementation that communicates with the JavaScript side.
 *
 * @param string $msg JSON encoded message for the JS runtime.
 * @return string Response from the JS runtime.
 */
function js( $msg ) {
return '';
}
}

if ( ! function_exists( 'next_js_url_id' ) ) {
/**
 * Generate monotonically increasing identifiers for JS-backed objects.
 *
 * @return int Next identifier.
 */
function next_js_url_id() {
static $id = 0;
$id++;
return $id;
}
}

/**
 * PHP wrapper for JavaScript's `URL` class.
 */
class URL {
/**
 * Identifier of the JS-side instance.
 *
 * @var int
 */
private $id;

/**
 * Instantiate a new URL object in JavaScript.
 *
 * @param string      $url  The URL string.
 * @param string|null $base Optional base URL.
 */
public function __construct( $url, $base = null ) {
$this->id = next_js_url_id();
$msg     = array(
'target' => 'URL',
'op'     => 'new',
'id'     => $this->id,
'url'    => (string) $url,
);
if ( null !== $base ) {
$msg['base'] = (string) $base;
}
js( json_encode( $msg ) );
}

/**
 * Perform an operation on the JS URL instance.
 *
 * @param string $op   Operation (get/set/call).
 * @param array  $args Additional arguments.
 * @return string Response from JS.
 */
private function call( $op, $args = array() ) {
$args['target'] = 'URL';
$args['op']     = $op;
$args['id']     = $this->id;
return js( json_encode( $args ) );
}

/**
 * Generic getter for scalar URL properties.
 *
 * @param string $prop Property name.
 * @return string
 */
private function get_prop( $prop ) {
return $this->call( 'get', array( 'prop' => $prop ) );
}

/**
 * Generic setter for scalar URL properties.
 *
 * @param string $prop  Property name.
 * @param string $value Value to set.
 * @return void
 */
private function set_prop( $prop, $value ) {
$this->call( 'set', array( 'prop' => $prop, 'value' => (string) $value ) );
}

/**
 * URL.href getter.
 *
 * @return string
 */
public function get_href() {
return $this->get_prop( 'href' );
}

/**
 * URL.href setter.
 *
 * @param string $value
 * @return void
 */
public function set_href( $value ) {
$this->set_prop( 'href', $value );
}

// -- Additional property getters and setters --
public function get_protocol() { return $this->get_prop( 'protocol' ); }
public function set_protocol( $value ) { $this->set_prop( 'protocol', $value ); }
public function get_username() { return $this->get_prop( 'username' ); }
public function set_username( $value ) { $this->set_prop( 'username', $value ); }
public function get_password() { return $this->get_prop( 'password' ); }
public function set_password( $value ) { $this->set_prop( 'password', $value ); }
public function get_host() { return $this->get_prop( 'host' ); }
public function set_host( $value ) { $this->set_prop( 'host', $value ); }
public function get_hostname() { return $this->get_prop( 'hostname' ); }
public function set_hostname( $value ) { $this->set_prop( 'hostname', $value ); }
public function get_port() { return $this->get_prop( 'port' ); }
public function set_port( $value ) { $this->set_prop( 'port', $value ); }
public function get_pathname() { return $this->get_prop( 'pathname' ); }
public function set_pathname( $value ) { $this->set_prop( 'pathname', $value ); }
public function get_search() { return $this->get_prop( 'search' ); }
public function set_search( $value ) { $this->set_prop( 'search', $value ); }
public function get_hash() { return $this->get_prop( 'hash' ); }
public function set_hash( $value ) { $this->set_prop( 'hash', $value ); }
public function get_origin() { return $this->get_prop( 'origin' ); }

/**
 * Convert URL to string via `toString()`.
 *
 * @return string
 */
public function to_string() {
return $this->call( 'call', array( 'method' => 'toString', 'args' => array() ) );
}

/**
 * Retrieve the associated URLSearchParams object.
 *
 * @return URLSearchParams
 */
public function get_search_params() {
$pid = (int) $this->get_prop( 'searchParams' );
return new URLSearchParams( $pid, true );
}
}

/**
 * PHP wrapper for JavaScript's `URLSearchParams` class.
 */
class URLSearchParams {
/**
 * Identifier of the JS-side instance.
 *
 * @var int
 */
private $id;

/**
 * Construct wrapper. If `$from_js` is false, a new JS object will be
 * created using the provided initialization string.
 *
 * @param mixed $init    Initialization value or identifier.
 * @param bool  $from_js Set to true if `$init` is an existing JS object ID.
 */
public function __construct( $init = '', $from_js = false ) {
if ( $from_js ) {
$this->id = (int) $init;
} else {
$this->id = next_js_url_id();
$msg     = array(
'target' => 'URLSearchParams',
'op'     => 'new',
'id'     => $this->id,
'init'   => (string) $init,
);
js( json_encode( $msg ) );
}
}

/**
 * Perform an operation on the JS URLSearchParams instance.
 *
 * @param string $method Method name.
 * @param array  $args   Arguments.
 * @return string Response.
 */
private function call( $method, $args = array() ) {
$msg = array(
'target' => 'URLSearchParams',
'op'     => 'call',
'id'     => $this->id,
'method' => $method,
'args'   => array_values( $args ),
);
return js( json_encode( $msg ) );
}

public function append( $name, $value ) { $this->call( 'append', array( $name, $value ) ); }
public function delete( $name ) { $this->call( 'delete', array( $name ) ); }
public function get( $name ) {
$res = $this->call( 'get', array( $name ) );
return '' === $res ? null : $res;
}
public function get_all( $name ) {
$res = $this->call( 'getAll', array( $name ) );
return '' === $res ? array() : json_decode( $res, true );
}
public function has( $name ) { return '1' === $this->call( 'has', array( $name ) ); }
public function set( $name, $value ) { $this->call( 'set', array( $name, $value ) ); }
public function to_string() { return $this->call( 'toString', array() ); }
}
