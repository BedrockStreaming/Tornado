<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;

/**
 * @internal
 */
class StreamEventLoop
{
    /** @var resource[] */
    private $readStreams = [];
    /** @var resource[] */
    private $writeStreams = [];
    /** @var PendingPromise<resource>[] */
    private $pendingPromises = [];

    /**
     * @param resource $stream
     *
     * @return PendingPromise<resource>
     */
    public function readable(EventLoop $eventLoop, $stream): PendingPromise
    {
        return $this->recordStream($eventLoop, $stream, $this->readStreams);
    }

    /**
     * @param resource $stream
     *
     * @return PendingPromise<resource>
     */
    public function writable(EventLoop $eventLoop, $stream): PendingPromise
    {
        return $this->recordStream($eventLoop, $stream, $this->writeStreams);
    }

    /**
     * @return \Generator<int, Promise<null>>
     */
    private function internalLoop(EventLoop $eventLoop): \Generator
    {
        $except = null;
        while ($this->readStreams || $this->writeStreams) {
            yield $eventLoop->idle();

            $read = $this->readStreams;
            $write = $this->writeStreams;
            $nbStreams = @\stream_select($read, $write, $except, 0);

            if ($nbStreams !== false) {
                foreach ($read as $stream) {
                    $streamId = (int) $stream;
                    $pendingPromise = $this->pendingPromises[$streamId];
                    unset($this->readStreams[$streamId]);
                    unset($this->pendingPromises[$streamId]);
                    $pendingPromise->resolve($stream);
                }

                foreach ($write as $stream) {
                    $streamId = (int) $stream;
                    $pendingPromise = $this->pendingPromises[$streamId];
                    unset($this->writeStreams[$streamId]);
                    unset($this->pendingPromises[$streamId]);
                    $pendingPromise->resolve($stream);
                }
            }

            yield $eventLoop->idle();
        }
    }

    /**
     * @param resource        $stream
     * @param array<resource> $streamsList
     *
     * @return PendingPromise<resource>
     */
    private function recordStream(EventLoop $eventLoop, $stream, array &$streamsList): PendingPromise
    {
        $streamId = (int) $stream;
        if (isset($this->pendingPromises[$streamId])) {
            return $this->pendingPromises[$streamId];
        }
        $this->pendingPromises[$streamId] = ($pendingPromise = PendingPromise::createHandled());
        $streamsList[$streamId] = $stream;

        if (count($this->pendingPromises) === 1) {
            $eventLoop->async($this->internalLoop($eventLoop));
        }

        return $pendingPromise;
    }
}
