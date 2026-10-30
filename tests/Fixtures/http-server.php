<?php

declare(strict_types=1);

/*
 * Minimal concurrent HTTP/1.1 server, for tests and benchmarks.
 * Usage: php http-server.php <port>
 *
 * Routes:
 *   /delay/{ms}?size={bytes}  responds after {ms} milliseconds with a body of {bytes} bytes
 *   /status/{code}            responds with the given status code
 *   /echo                     responds with a JSON description of the request
 *   /chunked                  responds with a chunked body
 *   /close                    responds without length, then closes the connection
 *   /drop                     responds as if the connection was kept alive, then closes it
 */

$port = (int) ($argv[1] ?? 8080);
$server = stream_socket_server("tcp://127.0.0.1:$port", $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "$errorMessage\n");
    exit(1);
}
stream_set_blocking($server, false);
echo "listening\n";

/** @var array<int, resource> $clients */
$clients = [];
/** @var array<int, string> $inputs */
$inputs = [];
/** @var array<int, string> $outputs */
$outputs = [];
/** @var array<int, bool> $closeAfterWrite */
$closeAfterWrite = [];
/** @var list<array{float, int, string, bool}> $scheduled responses: due time, client id, payload, close */
$scheduled = [];

$close = function (int $id) use (&$clients, &$inputs, &$outputs, &$closeAfterWrite): void {
    @fclose($clients[$id]);
    unset($clients[$id], $inputs[$id], $outputs[$id], $closeAfterWrite[$id]);
};

$respond = function (string $method, string $target, array $headers, string $body): array {
    $path = parse_url($target, PHP_URL_PATH) ?: '/';
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    $build = fn (int $status, string $content, array $extraHeaders = []) => "HTTP/1.1 $status Status\r\n"
        .implode('', array_map(fn ($header) => "$header\r\n", $extraHeaders))
        .'Content-Length: '.strlen($content)."\r\n\r\n".($method === 'HEAD' ? '' : $content);

    if (preg_match('#^/delay/(\d+)$#', $path, $matches)) {
        return [(int) $matches[1], $build(200, str_repeat('x', (int) ($query['size'] ?? 0))), false];
    }
    if (preg_match('#^/status/(\d{3})$#', $path, $matches)) {
        return [0, $build((int) $matches[1], "Status {$matches[1]}"), false];
    }
    if ($path === '/echo') {
        return [0, $build(200, (string) json_encode(compact('method', 'target', 'headers', 'body')), ['Content-Type: application/json', 'X-Multi: a', 'X-Multi: b']), false];
    }
    if ($path === '/chunked') {
        return [0, "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=1\r\nHello\r\n7\r\n, World\r\n0\r\nX-Trailer: 1\r\n\r\n", false];
    }
    if ($path === '/drop') {
        return [0, $build(200, 'Dropped'), true];
    }
    if ($path === '/close') {
        return [0, "HTTP/1.1 200 OK\r\nConnection: close\r\n\r\nUntil the end", true];
    }

    return [0, $build(404, 'Not Found'), false];
};

while (true) { // @phpstan-ignore while.alwaysTrue (stopped by a signal)
    $read = array_values($clients);
    $read[] = $server;
    $write = array_values(array_filter($clients, fn ($client) => ($outputs[(int) $client] ?? '') !== ''));
    $except = null;

    $timeout = 1_000_000;
    if ($scheduled) {
        $timeout = max(0, (int) ((min(array_column($scheduled, 0)) - microtime(true)) * 1_000_000));
    }
    if (@stream_select($read, $write, $except, 0, $timeout) === false) {
        continue;
    }

    foreach ($read as $stream) {
        if ($stream === $server) {
            while ($client = @stream_socket_accept($server, 0)) {
                stream_set_blocking($client, false);
                $clients[(int) $client] = $client;
                $inputs[(int) $client] = '';
                $outputs[(int) $client] = '';
            }
            continue;
        }

        $id = (int) $stream;
        $data = @fread($stream, 65536);
        if ($data === '' || $data === false) {
            if (feof($stream)) {
                $close($id);
            }
            continue;
        }
        $inputs[$id] .= $data;

        while (($headEnd = strpos($inputs[$id], "\r\n\r\n")) !== false) {
            $lines = explode("\r\n", substr($inputs[$id], 0, $headEnd));
            [$method, $target] = explode(' ', array_shift($lines));
            $headers = [];
            foreach ($lines as $line) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)][] = trim($value);
            }
            $length = (int) ($headers['content-length'][0] ?? 0);
            if (strlen($inputs[$id]) < $headEnd + 4 + $length) {
                break;
            }
            $body = substr($inputs[$id], $headEnd + 4, $length);
            $inputs[$id] = substr($inputs[$id], $headEnd + 4 + $length);

            [$delay, $payload, $closeConnection] = $respond($method, $target, $headers, $body);
            $scheduled[] = [microtime(true) + $delay / 1000, $id, $payload, $closeConnection];
        }
    }

    $now = microtime(true);
    foreach ($scheduled as $index => [$dueTime, $id, $payload, $closeConnection]) {
        if ($dueTime <= $now) {
            unset($scheduled[$index]);
            if (isset($clients[$id])) {
                $outputs[$id] .= $payload;
                $closeAfterWrite[$id] = $closeConnection;
            }
        }
    }

    foreach ($clients as $id => $client) {
        if ($outputs[$id] === '') {
            continue;
        }
        $written = @fwrite($client, $outputs[$id]);
        if ($written === false) {
            $close($id);
            continue;
        }
        $outputs[$id] = substr($outputs[$id], $written);
        if ($outputs[$id] === '' && ($closeAfterWrite[$id] ?? false)) {
            $close($id);
        }
    }
}
