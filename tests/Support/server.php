<?php

/**
 * Minimal single-threaded HTTPS fixture server used by the test suite to
 * exercise Client/Request/Response/OAuth against real cURL requests,
 * without touching the live Blipfoto API.
 *
 * Usage: php server.php <port> <cert-path> <key-path> <ready-file>
 *
 * Routes (matched against the resource path, i.e. before the trailing
 * ".json" that the SDK always appends):
 *
 *   /echo            -> 200, JSON echoing back method/path/query/headers/body,
 *                        plus fixed X-RateLimit-* response headers.
 *   /oauth/token      -> 200, JSON with a canned OAuth token payload.
 *   /error/http-500   -> 500 status, arbitrary body (triggers InvalidResponseException).
 *   /error/malformed  -> 200 status, non-JSON body (triggers InvalidResponseException).
 *   /error/api        -> 200 status, {"error":{"code":100,...}} (triggers ApiResponseException).
 *   /error/oauth       -> 200 status, {"error":{"code":31,...}} (triggers OAuthException).
 *   anything else     -> 404 with a JSON body (should still surface as InvalidResponseException).
 */

[, $port, $cert, $key, $readyFile] = $argv;

$contextOptions = [
    'ssl' => [
        'local_cert' => $cert,
        'local_pk' => $key,
        'allow_self_signed' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
];

$server = stream_socket_server(
    "ssl://127.0.0.1:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create($contextOptions)
);

if ($server === false) {
    fwrite(STDERR, "Failed to start test server: {$errstr} ({$errno})\n");
    exit(1);
}

// Signal readiness to the PHPUnit process waiting on this file.
file_put_contents($readyFile, 'ready');

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if ($conn === false) {
        continue;
    }
    handleConnection($conn);
    fclose($conn);
}

function handleConnection($conn): void
{
    stream_set_timeout($conn, 5);

    // Read the request line + headers.
    $headerData = '';
    while (!str_contains($headerData, "\r\n\r\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $headerData .= $chunk;
    }

    if ($headerData === '') {
        return;
    }

    [$head, $rest] = explode("\r\n\r\n", $headerData, 2) + [1 => ''];
    $lines = explode("\r\n", $head);
    $requestLine = array_shift($lines);
    [$method, $target] = explode(' ', $requestLine) + [1 => '/'];

    $headers = [];
    foreach ($lines as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
    }

    $contentLength = (int) ($headers['Content-Length'] ?? 0);
    $body = $rest;
    while (strlen($body) < $contentLength) {
        $chunk = fread($conn, $contentLength - strlen($body));
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $body .= $chunk;
    }

    [$path, $query] = explode('?', $target, 2) + [1 => ''];
    parse_str($query, $queryParams);

    // Strip the trailing ".json" the SDK always appends.
    $resource = preg_replace('/\.json$/', '', ltrim($path, '/'));

    [$status, $responseHeaders, $responseBody] = route($resource, $method, $queryParams, $headers, $body);

    $statusTexts = [200 => 'OK', 400 => 'Bad Request', 404 => 'Not Found', 500 => 'Internal Server Error'];
    $statusText = $statusTexts[$status] ?? 'OK';

    $out = "HTTP/1.1 {$status} {$statusText}\r\n";
    $out .= "Content-Type: application/json\r\n";
    $out .= 'Content-Length: ' . strlen($responseBody) . "\r\n";
    $out .= "Connection: close\r\n";
    foreach ($responseHeaders as $name => $value) {
        $out .= "{$name}: {$value}\r\n";
    }
    $out .= "\r\n" . $responseBody;

    fwrite($conn, $out);
}

function route(string $resource, string $method, array $query, array $headers, string $body): array
{
    if ($resource === 'error/http-500') {
        return [500, [], '{"data":null}'];
    }

    if ($resource === 'error/malformed') {
        return [200, [], 'this is not json'];
    }

    if ($resource === 'error/api') {
        return [200, [], json_encode(['error' => ['code' => 100, 'message' => 'Something broke']])];
    }

    if ($resource === 'error/oauth') {
        return [200, [], json_encode(['error' => ['code' => 31, 'message' => 'Invalid token']])];
    }

    if ($resource === 'oauth/token') {
        parse_str($body, $fields);
        return [200, [], json_encode(['data' => [
            'token' => [
                'access_token' => 'test-access-token',
                'scope' => $fields['scope'] ?? null,
            ],
        ]])];
    }

    if ($resource === 'echo') {
        return [
            200,
            ['X-RateLimit-Limit' => '500', 'X-RateLimit-Remaining' => '499', 'X-RateLimit-Reset' => '1700000000'],
            json_encode(['data' => [
                'echo' => [
                    'method' => $method,
                    'path' => $resource,
                    'query' => $query,
                    'headers' => $headers,
                    'content_type' => $headers['Content-Type'] ?? null,
                    'authorization' => $headers['Authorization'] ?? null,
                    // Base64-encoded: the raw body can contain binary file
                    // data, which is not valid UTF-8 and would otherwise
                    // make json_encode() silently fail on this whole payload.
                    'body_base64' => base64_encode($body),
                    'body_length' => strlen($body),
                ],
            ]]),
        ];
    }

    return [404, [], json_encode(['error' => ['code' => 404, 'message' => 'Not found']])];
}
