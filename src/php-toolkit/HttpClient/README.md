# HTTP Client

An asynchronous HTTP client library.

### Key Features

- **No dependencies:** Works on vanilla PHP without external libraries. `SocketClient` uses `stream_socket_client()` for non-blocking HTTP requests and `CurlClient` uses `curl_multi` for parallel requests.
- **Streaming support:** Enables efficient handling of large response bodies.
- **Progress monitoring:** Track the progress of requests and responses.
- **Concurrency limits:** Control the number of simultaneous connections.
- **PHP 7.2+ support and no dependencies:** Works on vanilla PHP without external libraries.

### Usage Example

```php
$requests = [
    new Request("[https://wordpress.org/latest.zip](https://wordpress.org/latest.zip)"),
    new Request("[https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml](https://raw.githubusercontent.com/wpaccessibility/a11y-theme-unit-test/master/a11y-theme-unit-test-data.xml)"),
];

// Creates the most appropriate client based for your environment.
$client = Client::create();
$client->enqueue($requests);

while ($client->await_next_event()) {
    $event = $client->get_event();
    $request = $client->get_request();

    if ($event === Client::EVENT_BODY_CHUNK_AVAILABLE) {
        $chunk = $client->get_response_body_chunk();
        // Process the chunk...
    }
    // Handle other events...
}
```

### TODO

* Request headers – accept string lines such as "Content-type: text/plain" instead of key-value pairs. K/V pairs
  are confusing and lead to accidental errors such as `0: Content-type: text/plain`. They also diverge from the
  format that curl accepts.
* Response caching – add a custom cache handler for easy caching of the same URLs
* Response caching – support HTTP cache-control headers
