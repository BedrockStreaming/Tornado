<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Revolt\Internal;

use M6Web\Tornado\Adapter\Revolt\TimeoutException;
use Revolt\EventLoop as Loop;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * Non-blocking socket whose I/O methods suspend the current fiber until the socket is ready.
 * They must be called from a fiber other than the event loop's one.
 */
final class Connection
{
    private const READ_CHUNK_SIZE = 65536;

    private string $buffer = '';
    private int $receivedBytes = 0;
    private bool $isClosed = false;
    private ?float $deadline = null;
    private string $deadlineMessage = '';

    /**
     * @param resource $stream
     */
    private function __construct(private $stream)
    {
    }

    /**
     * Monotonic time in seconds, the reference of deadlines.
     */
    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }

    /**
     * Opens a TCP connection, with TLS if $secure is true, bounded by a deadline as set by setDeadline().
     * Hostname resolution is blocking, like with PHP stream functions, and is not bounded by the deadline.
     *
     * @param array<string, mixed> $sslContextOptions
     */
    public static function open(string $host, int $port, bool $secure, ?float $deadline, string $deadlineMessage, array $sslContextOptions): self
    {
        $peerName = trim($host, '[]');
        $context = stream_context_create(['ssl' => $sslContextOptions + ['peer_name' => $peerName, 'SNI_enabled' => true]]);

        $stream = @stream_socket_client(
            "tcp://$host:$port",
            $errorCode,
            $errorMessage,
            null,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new \RuntimeException("Unable to connect to $host:$port: $errorMessage", $errorCode);
        }
        stream_set_blocking($stream, false);
        // PHP buffering would hide pending data from the event loop.
        stream_set_read_buffer($stream, 0);

        $connection = new self($stream);
        $connection->setDeadline($deadline, $deadlineMessage);
        try {
            $connection->awaitWritable();
            if (stream_socket_get_name($stream, true) === false) {
                throw new \RuntimeException("Unable to connect to $host:$port: connection refused");
            }

            while ($secure) {
                $result = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($result === true) {
                    break;
                }
                if ($result === false) {
                    throw new \RuntimeException("TLS handshake with $host:$port failed: ".(error_get_last()['message'] ?? 'unknown error'));
                }
                $connection->awaitReadable();
            }
        } catch (\Throwable $throwable) {
            $connection->close();
            throw $throwable;
        }

        return $connection;
    }

    /**
     * Makes I/O operations throw a TimeoutException with $message once $deadline is reached, see now().
     */
    public function setDeadline(?float $deadline, string $message = ''): void
    {
        $this->deadline = $deadline;
        $this->deadlineMessage = $message;
    }

    public function write(string $data): void
    {
        while ($data !== '') {
            $written = @fwrite($this->stream, $data);
            if ($written === false) {
                throw new ConnectionClosedException('Unable to write on the connection.');
            }
            $data = substr($data, $written);
            if ($data !== '') {
                $this->awaitWritable();
            }
        }
    }

    /**
     * Returns data up to $delimiter, and consumes the delimiter.
     */
    public function readUntil(string $delimiter, int $maxLength): string
    {
        $searchOffset = 0;
        while (($position = strpos($this->buffer, $delimiter, $searchOffset)) === false) {
            if (strlen($this->buffer) > $maxLength) {
                throw new \RuntimeException("Line exceeds $maxLength bytes.");
            }
            $searchOffset = max(0, strlen($this->buffer) - strlen($delimiter) + 1);
            $this->fill();
        }

        $data = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + strlen($delimiter));

        return $data;
    }

    public function readExactly(int $length): string
    {
        while (strlen($this->buffer) < $length) {
            $this->fill();
        }

        $data = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);

        return $data;
    }

    /**
     * Writes $length bytes to $sink, or all data until the peer closes the connection if $length is null.
     * Data is not buffered, to avoid copying large bodies.
     *
     * @param resource $sink
     */
    public function copyTo($sink, ?int $length = null): void
    {
        while ($length === null || strlen($this->buffer) < $length) {
            fwrite($sink, $this->buffer);
            $length = $length === null ? null : $length - strlen($this->buffer);
            $this->buffer = '';
            if (!$this->tryFill()) {
                if ($length === null) {
                    return;
                }
                throw new ConnectionClosedException('Connection closed by peer.');
            }
        }

        fwrite($sink, substr($this->buffer, 0, $length));
        $this->buffer = substr($this->buffer, $length);
    }

    public function getReceivedBytes(): int
    {
        return $this->receivedBytes;
    }

    public function hasBufferedData(): bool
    {
        return $this->buffer !== '';
    }

    public function close(): void
    {
        if (!$this->isClosed) {
            $this->isClosed = true;
            @fclose($this->stream);
        }
    }

    private function fill(): void
    {
        if (!$this->tryFill()) {
            throw new ConnectionClosedException('Connection closed by peer.');
        }
    }

    /**
     * @return bool false when the peer closed the connection
     */
    private function tryFill(): bool
    {
        while (true) {
            // Read before waiting: TLS may hold decrypted data the event loop cannot see.
            $chunk = @fread($this->stream, self::READ_CHUNK_SIZE);
            if ($chunk === false) {
                throw new ConnectionClosedException('Unable to read from the connection.');
            }
            if ($chunk !== '') {
                $this->buffer .= $chunk;
                $this->receivedBytes += strlen($chunk);
                // A peer sending data continuously would otherwise never be waited for, hence never timed out.
                if ($this->deadline !== null && self::now() >= $this->deadline) {
                    throw new TimeoutException($this->deadlineMessage);
                }

                return true;
            }
            if (feof($this->stream)) {
                return false;
            }
            $this->awaitReadable();
        }
    }

    private function awaitReadable(): void
    {
        $suspension = Loop::getSuspension();
        $this->await($suspension, Loop::onReadable($this->stream, static fn () => $suspension->resume()));
    }

    private function awaitWritable(): void
    {
        $suspension = Loop::getSuspension();
        $this->await($suspension, Loop::onWritable($this->stream, static fn () => $suspension->resume()));
    }

    /**
     * @param Loop\Suspension<mixed> $suspension
     */
    private function await(Loop\Suspension $suspension, string $watcherId): void
    {
        $timerId = null;
        if ($this->deadline !== null) {
            $remaining = $this->deadline - self::now();
            if ($remaining <= 0) {
                Loop::cancel($watcherId);
                throw new TimeoutException($this->deadlineMessage);
            }
            $message = $this->deadlineMessage;
            $timerId = Loop::delay($remaining, static fn () => $suspension->throw(new TimeoutException($message)));
        }

        try {
            $suspension->suspend();
        } finally {
            Loop::cancel($watcherId);
            if ($timerId !== null) {
                Loop::cancel($timerId);
            }
        }
    }
}
