<?php

namespace M6WebTest\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use PHPUnit\Framework\TestCase;

abstract class EventLoopTest extends TestCase
{
    use
        EventLoopTest\PromiseAllTest,
        EventLoopTest\PromiseRaceTest;

    abstract protected function createEventLoop(): EventLoop;

    public function testFulfilledPromise()
    {
        $expectedValue = 1664;

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseFulfilled($expectedValue);

        $this->assertEquals(
            $expectedValue,
            $eventLoop->wait($promise)
        );
    }

    public function testRejectedPromise()
    {
        $expectedException = new class() extends \Exception {
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseRejected($expectedException);

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    public function testDummyGenerator()
    {
        $expectedValue = 42;
        $generator = (function ($value): \Generator {
            return $value;
            yield;  // Mandatory if we want to create a generator
        })($expectedValue);

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($generator);

        $this->assertEquals(
            $expectedValue,
            $eventLoop->wait($promise)
        );
    }

    public function testDummyGeneratorThrowing()
    {
        $expectedException = new class() extends \Exception {
        };
        $generator = (function ($exception): \Generator {
            throw $exception;
            yield;  // Mandatory if we want to create a generator
        })($expectedException);

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($generator);

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    public function testYieldingGenerator()
    {
        $createGenerator = function (EventLoop $eventLoop, $a, $b, $c): \Generator {
            $result = '';
            $result .= yield $eventLoop->promiseFulfilled($a);
            $result .= yield $eventLoop->promiseFulfilled($b);
            $result .= yield $eventLoop->promiseFulfilled($c);

            return $result;
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($createGenerator($eventLoop, 'A', 'B', 'C'));

        $this->assertEquals('ABC', $eventLoop->wait($promise));
    }

    public function testYieldingGeneratorWithRejectedPromise()
    {
        $createGenerator = function (EventLoop $eventLoop, \Exception $exception): \Generator {
            try {
                yield $eventLoop->promiseRejected($exception);
            } catch (\Throwable $exception) {
                return $exception;
            }
        };
        $expectedException = new class() extends \Exception {
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($createGenerator($eventLoop, $expectedException));

        $this->assertEquals(
            $expectedException,
            $eventLoop->wait($promise)
        );
    }

    public function testSubGenerators()
    {
        $eventLoop = $this->createEventLoop();
        $createGenerator = function (Promise ...$promises) use ($eventLoop): \Generator {
            $result = [];
            foreach ($promises as $promise) {
                $result[] = yield $promise;
            }

            return $result;
        };

        $promise = $eventLoop->async(
            $createGenerator(
                $eventLoop->promiseFulfilled(1),
                $eventLoop->async($createGenerator(
                    $eventLoop->promiseFulfilled(2),
                    $eventLoop->promiseFulfilled(3)
                )),
                $eventLoop->promiseFulfilled(4)
            )
        );

        $this->assertEquals([1, [2, 3], 4], $eventLoop->wait($promise));
    }

    public function testEventLoopFirstTick()
    {
        $eventLoop = $this->createEventLoop();

        $count = 0;
        $oneStepGenerator = function (EventLoop $eventLoop, int &$count): \Generator {
            yield $eventLoop->promiseFulfilled(null);
            $count++;
        };
        $eventLoop->async($oneStepGenerator($eventLoop, $count));
        $eventLoop->wait($eventLoop->promiseFulfilled(null));

        $this->assertEquals(1, $count);
    }

    public function testIdle($expectedSequence = 'ABCABA')
    {
        $eventLoop = $this->createEventLoop();
        $outputBuffer = '';
        $createIdleGenerator = function (string $id, int $count) use ($eventLoop, &$outputBuffer): \Generator {
            while ($count--) {
                yield $eventLoop->idle();
                $outputBuffer .= $id;
            }
        };

        $promise = $eventLoop->promiseAll(
            $eventLoop->async($createIdleGenerator('A', 3)),
            $eventLoop->async($createIdleGenerator('B', 2)),
            $eventLoop->async($createIdleGenerator('C', 1))
        );

        $this->assertEquals([null, null, null], $eventLoop->wait($promise));
        $this->assertEquals($expectedSequence, $outputBuffer);
    }

    public function testDeferredResolved()
    {
        $expectedValue = 'Caramba!';
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred();

        $resolverGenerator = function (EventLoop $eventLoop, Deferred $deferred) use ($expectedValue): \Generator {
            for ($count = 0; $count < 10; $count++) {
                yield $eventLoop->idle();
            }

            $deferred->resolve($expectedValue);
        };
        $waitingGenerator = function (Promise $promise): \Generator {
            return yield $promise;
        };

        $eventLoop->async($resolverGenerator($eventLoop, $deferred));
        $this->assertEquals(
            $expectedValue,
            $eventLoop->wait(
                $eventLoop->async(
                    $waitingGenerator($deferred->getPromise())
                )
            )
        );
    }

    public function testDeferredRejected()
    {
        $expectedException = new class() extends \Exception {
        };
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred();

        $resolverGenerator = function (EventLoop $eventLoop, Deferred $deferred) use ($expectedException): \Generator {
            for ($count = 0; $count < 10; $count++) {
                yield $eventLoop->idle();
            }

            $deferred->reject($expectedException);
        };
        $waitingGenerator = function (Promise $promise): \Generator {
            return yield $promise;
        };

        $eventLoop->async($resolverGenerator($eventLoop, $deferred));
        $promise = $eventLoop->async($waitingGenerator($deferred->getPromise()));

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    /**
     * Returns a pair of two connected streams.
     *
     * @return resource[]
     */
    private function createStreamPair()
    {
        $domain = (DIRECTORY_SEPARATOR === '\\') ? STREAM_PF_INET : STREAM_PF_UNIX;
        $sockets = stream_socket_pair($domain, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        foreach ($sockets as $socket) {
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
        }

        return $sockets;
    }

    public function testReadableStream($expectedSequence = 'W0R0W12345R12R34W6R56R')
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
                yield $eventLoop->idle();
            }
            fclose($stream);
        };

        $readFromStream = function (EventLoop $eventLoop, $stream) use (&$sequence): \Generator {
            do {
                $token = fread(yield $eventLoop->readable($stream), 2);
                $sequence .= "R$token";
            } while (strlen($token));

            return $sequence;
        };

        $eventLoop = $this->createEventLoop();
        $eventLoop->async($writeToStream($eventLoop, $streamIn, $tokens));
        $readPromise = $eventLoop->async($readFromStream($eventLoop, $streamOut));

        $this->assertSame($expectedSequence, $eventLoop->wait($readPromise));
    }
}
