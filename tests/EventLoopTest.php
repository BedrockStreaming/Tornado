<?php

namespace M6WebTest\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use PHPUnit\Framework\TestCase;

abstract class EventLoopTest extends TestCase
{
    use
        EventLoopTest\StreamsTest,
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

    public function testYieldingForTheSameFulfilledPromise()
    {
        $eventLoop = $this->createEventLoop();
        $commonPromise = $eventLoop->idle();

        $createGenerator = function ($returnedValue) use ($commonPromise): \Generator {
            yield $commonPromise;

            return $returnedValue;
        };

        $promise = $eventLoop->promiseAll(
            $eventLoop->async($createGenerator(1)),
            $eventLoop->async($createGenerator(2))
        );

        $this->assertEquals(
            [1, 2],
            $eventLoop->wait($promise)
        );
    }

    public function testYieldingForTheSameRejectededPromise()
    {
        $expectedMessage = 'Common Exception';
        $eventLoop = $this->createEventLoop();
        $commonPromise = $eventLoop->promiseRejected(new \Exception($expectedMessage));

        $createGenerator = function () use ($commonPromise): \Generator {
            try {
                yield $commonPromise;
            } catch (\Throwable $throwable) {
                return $throwable->getMessage();
            }

            return 'No Exception';
        };

        $promise = $eventLoop->promiseAll(
            $eventLoop->async($createGenerator()),
            $eventLoop->async($createGenerator())
        );

        $this->assertEquals(
            [$expectedMessage, $expectedMessage],
            $eventLoop->wait($promise)
        );
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
}
