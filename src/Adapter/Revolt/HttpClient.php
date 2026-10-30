<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Revolt;

use M6Web\Tornado\Adapter\Revolt\Internal\Connection;
use M6Web\Tornado\Adapter\Revolt\Internal\ConnectionClosedException;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Revolt\EventLoop as Loop;
use Revolt\EventLoop\Suspension;

/**
 * HTTP/1.x client relying on Revolt to be notified of socket activity, instead of polling,
 * so that waiting for responses does not consume CPU.
 *
 * Requests run in fibers, connections are kept alive and reused per origin.
 * Redirections are not followed, response bodies are not decoded, and HTTP/2 requests are sent as HTTP/1.1.
 */
class HttpClient implements \M6Web\Tornado\HttpClient
{
    private const MAX_HEADERS_LENGTH = 65536;
    // Creating a fiber allocates its stack: reusing them saves several system calls per request.
    private const MAX_IDLE_WORKERS = 64;

    /** @var array<string, list<Connection>> */
    private array $idleConnections = [];
    /** @var list<Suspension<array{RequestInterface, Deferred}>> */
    private array $idleWorkers = [];

    /**
     * @param float                $timeout           maximum inactivity duration on a connection, in seconds
     * @param array<string, mixed> $sslContextOptions see https://www.php.net/manual/en/context.ssl.php
     */
    public function __construct(
        private readonly EventLoop $eventLoop,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly float $timeout = 30.0,
        private readonly array $sslContextOptions = [],
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): Promise
    {
        $deferred = $this->eventLoop->deferred();

        if ($worker = array_pop($this->idleWorkers)) {
            $worker->resume([$request, $deferred]);
        } else {
            (new \Fiber($this->work(...)))->start($request, $deferred);
        }

        return $deferred->getPromise();
    }

    private function work(RequestInterface $request, Deferred $deferred): void
    {
        while (true) {
            $response = null;
            try {
                $response = $this->send($request);
            } catch (\Throwable $throwable) {
                $deferred->reject($throwable);
            }
            if ($response !== null) {
                $deferred->resolve($response);
            }

            if (count($this->idleWorkers) >= self::MAX_IDLE_WORKERS) {
                return;
            }
            $suspension = Loop::getSuspension();
            $this->idleWorkers[] = $suspension;
            [$request, $deferred] = $suspension->suspend();
        }
    }

    private function send(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $scheme = strtolower($uri->getScheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new \InvalidArgumentException("Unsupported scheme [$scheme] in URI [$uri].");
        }
        $host = $uri->getHost();
        if ($host === '') {
            throw new \InvalidArgumentException("Missing host in URI [$uri].");
        }
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $origin = "$scheme://$host:$port";

        $payload = $this->serializeRequest($request);

        while ($connection = $this->takeIdleConnection($origin)) {
            $receivedBytes = $connection->getReceivedBytes();
            try {
                return $this->exchange($connection, $origin, $request, $payload);
            } catch (ConnectionClosedException $exception) {
                // The server may close an idle connection at any time: retry on another one if nothing was received.
                if ($connection->getReceivedBytes() !== $receivedBytes) {
                    throw $exception;
                }
            }
        }

        $connection = Connection::open($host, $port, $scheme === 'https', $this->timeout, $this->sslContextOptions);

        return $this->exchange($connection, $origin, $request, $payload);
    }

    private function exchange(Connection $connection, string $origin, RequestInterface $request, string $payload): ResponseInterface
    {
        try {
            $connection->write($payload);
            [$response, $isReusable] = $this->readResponse($connection, $request);
        } catch (\Throwable $throwable) {
            $connection->close();
            throw $throwable;
        }

        if ($isReusable && !$connection->hasBufferedData()) {
            $this->idleConnections[$origin][] = $connection;
        } else {
            $connection->close();
        }

        return $response;
    }

    private function takeIdleConnection(string $origin): ?Connection
    {
        if (!isset($this->idleConnections[$origin])) {
            return null;
        }
        $connection = array_pop($this->idleConnections[$origin]);
        if ($this->idleConnections[$origin] === []) {
            unset($this->idleConnections[$origin]);
        }

        return $connection;
    }

    private function serializeRequest(RequestInterface $request): string
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->seek(0);
        }
        $content = $body->getContents();

        $version = $request->getProtocolVersion() === '1.0' ? '1.0' : '1.1';
        $payload = "{$request->getMethod()} {$request->getRequestTarget()} HTTP/$version\r\n";

        if (!$request->hasHeader('Host')) {
            $uri = $request->getUri();
            $payload .= 'Host: '.$uri->getHost().($uri->getPort() !== null ? ':'.$uri->getPort() : '')."\r\n";
        }
        foreach ($request->getHeaders() as $name => $values) {
            // The body is sent at once, its framing is computed below.
            if (in_array(strtolower((string) $name), ['content-length', 'transfer-encoding'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $payload .= "$name: $value\r\n";
            }
        }
        if ($content !== '' || in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $payload .= 'Content-Length: '.strlen($content)."\r\n";
        }

        return "$payload\r\n$content";
    }

    /**
     * @return array{ResponseInterface, bool} the response, and whether the connection can be reused
     */
    private function readResponse(Connection $connection, RequestInterface $request): array
    {
        // Skip informational responses, except "101 Switching Protocols" that ends the HTTP exchange.
        do {
            [$version, $status, $reason, $headerLines] = $this->parseHead($connection->readUntil("\r\n\r\n", self::MAX_HEADERS_LENGTH));
        } while ($status >= 100 && $status < 200 && $status !== 101);

        $headers = [];
        foreach ($headerLines as [$name, $value]) {
            $headers[strtolower($name)][] = $value;
        }

        $connectionTokens = strtolower(implode(',', [...($headers['connection'] ?? []), ...$request->getHeader('Connection')]));
        $isReusable = $version === '1.1'
            ? !str_contains($connectionTokens, 'close')
            : str_contains($connectionTokens, 'keep-alive');

        $body = fopen('php://temp', 'w+b');
        assert($body !== false);
        $transferEncoding = strtolower(implode(',', $headers['transfer-encoding'] ?? []));
        if ($request->getMethod() === 'HEAD' || $status < 200 || $status === 204 || $status === 304) {
            $isReusable = $isReusable && $status !== 101;
        } elseif ($transferEncoding !== '') {
            if (!str_ends_with(rtrim($transferEncoding), 'chunked')) {
                throw new \RuntimeException("Unsupported transfer encoding [$transferEncoding].");
            }
            $this->copyChunkedBody($connection, $body);
        } elseif (isset($headers['content-length'])) {
            $length = $headers['content-length'][0];
            if (!ctype_digit($length)) {
                throw new \RuntimeException("Invalid content length [$length].");
            }
            $connection->copyTo($body, (int) $length);
        } else {
            $connection->copyTo($body);
            $isReusable = false;
        }
        rewind($body);

        $response = $this->responseFactory->createResponse($status, $reason)->withProtocolVersion($version);
        foreach ($headerLines as [$name, $value]) {
            $response = $response->withAddedHeader($name, $value);
        }

        return [$response->withBody($this->streamFactory->createStreamFromResource($body)), $isReusable];
    }

    /**
     * @return array{string, int, string, list<array{string, string}>} version, status, reason and headers as [name, value] pairs
     */
    private function parseHead(string $head): array
    {
        $lines = explode("\r\n", $head);
        if (!preg_match('#^HTTP/(\d\.\d) (\d{3})(?: (.*))?$#', array_shift($lines), $matches)) {
            throw new \RuntimeException('Invalid HTTP response status line.');
        }

        $headers = [];
        foreach ($lines as $line) {
            $separatorPosition = strpos($line, ':');
            if ($separatorPosition === false || $separatorPosition === 0) {
                throw new \RuntimeException("Invalid HTTP response header [$line].");
            }
            $headers[] = [substr($line, 0, $separatorPosition), trim(substr($line, $separatorPosition + 1), " \t")];
        }

        return [$matches[1], (int) $matches[2], $matches[3] ?? '', $headers];
    }

    /**
     * @param resource $body
     */
    private function copyChunkedBody(Connection $connection, $body): void
    {
        while (true) {
            $sizeLine = $connection->readUntil("\r\n", self::MAX_HEADERS_LENGTH);
            $size = trim(explode(';', $sizeLine, 2)[0]);
            if (!ctype_xdigit($size)) {
                throw new \RuntimeException("Invalid chunk size [$sizeLine].");
            }
            if ($size = hexdec($size)) {
                $connection->copyTo($body, (int) $size);
                $connection->readExactly(2);
                continue;
            }

            // Discard trailers.
            while ($connection->readUntil("\r\n", self::MAX_HEADERS_LENGTH) !== '') {
            }

            return;
        }
    }
}
