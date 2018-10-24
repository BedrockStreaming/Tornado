<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;

trait StreamsTest
{
    abstract protected function createEventLoop(): EventLoop;

    /**
     * Returns a pair of two connected streams.
     *
     * @return resource[]
     */
    private function createStreamPair()
    {
        $domain = (DIRECTORY_SEPARATOR === '\\') ? STREAM_PF_INET : STREAM_PF_UNIX;
        $sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($sockets === false) {
            throw new \Error('Cannot create socket pair for tests.');
        }

        foreach ($sockets as $socket) {
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
        }

        return $sockets;
    }

    public function testStreamShouldReadFromWritable($expectedSequence = 'W0R0W12345R12R34W6R56R')
    {
        $tokens = ['0', '12345', '6'];
        [$streamIn, $streamOut] = $this->createStreamPair();
        stream_set_blocking($streamOut, false);
        $sequence = '';

        $writeToStream = function (EventLoop $eventLoop, $stream, array $tokens) use (&$sequence): \Generator {
            // At the beginning, nothing can be read from the stream
            yield $eventLoop->idle();
            foreach ($tokens as $token) {
                fwrite(yield $eventLoop->writable($stream), $token);
                $sequence .= "W$token";
                // Write twice slower
                yield $eventLoop->idle();
                //yield $eventLoop->idle();
            }
            fclose($stream);
        };

        $readFromStream = function (EventLoop $eventLoop, $stream) use (&$sequence): \Generator {
            do {
                $token = fread(yield $eventLoop->readable($stream), 2);
                if ($token === false) {
                    throw new \Error('Failed to read from stream in tests.');
                }
                $sequence .= "R$token";
            } while (strlen($token));

            return $sequence;
        };

        $eventLoop = $this->createEventLoop();
        $eventLoop->async($writeToStream($eventLoop, $streamIn, $tokens));
        $readPromise = $eventLoop->async($readFromStream($eventLoop, $streamOut));

        $this->assertSame($expectedSequence, $eventLoop->wait($readPromise));
    }

    public function testStreamShouldBeWritableIfOpened()
    {
        $eventLoop = $this->createEventLoop();
        [$streamIn, $streamOut] = $this->createStreamPair();

        // If output stream is closed, input stream is always writable
        fclose($streamOut);
        $stream = $eventLoop->wait($eventLoop->writable($streamIn));
        $this->assertSame($streamIn, $stream);
    }

    public function testStreamShouldNotBeWritableIfClosed()
    {
        $eventLoop = $this->createEventLoop();
        [$streamIn, $streamOut] = $this->createStreamPair();

        $waitSomeTicks = function () use ($eventLoop) {
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            yield $eventLoop->idle();

            return 'Aborted';
        };

        // If stream is closed, the promise will never be resolved
        fclose($streamIn);
        $result = $eventLoop->wait(
            $eventLoop->promiseRace(
                $eventLoop->async($waitSomeTicks()),
                $eventLoop->writable($streamIn)
            )
        );

        $this->assertSame('Aborted', $result);
    }

    public function testStreamShouldNotBeReadableIfClosed()
    {
        $eventLoop = $this->createEventLoop();
        [$streamIn, $streamOut] = $this->createStreamPair();

        $waitSomeTicks = function () use ($eventLoop) {
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            yield $eventLoop->idle();

            return 'Aborted';
        };

        // If stream is closed, the promise will never be resolved
        fclose($streamOut);
        $result = $eventLoop->wait(
            $eventLoop->promiseRace(
                $eventLoop->async($waitSomeTicks()),
                $eventLoop->readable($streamOut)
            )
        );

        $this->assertSame('Aborted', $result);
    }
}
