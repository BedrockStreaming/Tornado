<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

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
    /** @var SwooleDeferred[] */
    private $pendingPromises = [];

    public function readable(EventLoop $eventLoop, $stream): Promise
    {
        return $this->recordStream($eventLoop, $stream, $this->readStreams);
    }

    public function writable(EventLoop $eventLoop, $stream): Promise
    {
        return $this->recordStream($eventLoop, $stream, $this->writeStreams);
    }

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

    private function recordStream(EventLoop $eventLoop, $stream, array &$streamsList): Promise
    {
        $streamId = (int) $stream;
        if (isset($this->pendingPromises[$streamId])) {
            return $this->pendingPromises[$streamId]->getPromise();
        }
        $this->pendingPromises[$streamId] = ($pendingPromise = new SwooleDeferred());
        $streamsList[$streamId] = $stream;

        if (count($this->pendingPromises) === 1) {
            $eventLoop->async($this->internalLoop($eventLoop));
        }

        return $pendingPromise->getPromise();
    }
}
