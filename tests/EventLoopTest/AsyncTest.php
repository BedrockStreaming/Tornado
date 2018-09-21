<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;

trait AsyncTest
{
    abstract protected function createEventLoop(): EventLoop;

    public function testDummyGenerator()
    {
        $expectedValue = 42;
        $generator = (function ($value): \Generator {
            return $value;
            yield;  // Mandatory if we want to create a generator
        })($expectedValue);

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($generator);

        $this->assertSame(
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

    public function testYieldingInvalidValueMayThrowAnError()
    {
        $createGenerator = function (): \Generator {
            yield 'Something that is not a promise.';
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($createGenerator());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Asynchronous function is yielding a [string] instead of a Promise.');
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

        $this->assertSame('ABC', $eventLoop->wait($promise));
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

        $this->assertSame(
            $expectedException,
            $eventLoop->wait($promise)
        );
    }

    public function testSubGenerators()
    {
        $eventLoop = $this->createEventLoop();
        $createGenerator = function (Promise ...$promises): \Generator {
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

        $this->assertSame([1, [2, 3], 4], $eventLoop->wait($promise));
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

        $this->assertSame(
            [1, 2],
            $eventLoop->wait($promise)
        );
    }

    public function testYieldingForTheSameRejectedPromise()
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

        $this->assertSame(
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

        $this->assertSame(1, $count);
    }

    public function testEventLoopShouldThrowInCaseOfUncaughtExceptionInBackgroundGenerator()
    {
        $eventLoop = $this->createEventLoop();

        $failingGenerator = function () use ($eventLoop) {
            yield $eventLoop->idle();
            throw new \Exception('This is a failure');
        };

        $waitingGenerator = function () use ($eventLoop) {
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            yield $eventLoop->idle();
        };

        $ignoredBackgroundPromise = $eventLoop->async($failingGenerator());
        $promiseSuccess = $eventLoop->async($waitingGenerator());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This is a failure');
        $eventLoop->wait($promiseSuccess);
    }

    public function testEventLoopShouldNotThrowInCaseOfExplicitlyRejectedPromise()
    {
        $eventLoop = $this->createEventLoop();

        $generatorWaitALittle = function () use ($eventLoop) {
            yield $eventLoop->idle();
            yield $eventLoop->idle();
        };

        $unwatchedRejectedPromise = $eventLoop->promiseRejected(new \Exception('Rejected Promise'));
        $unwatchedDeferred = $eventLoop->deferred();
        $unwatchedDeferred->reject(new \Exception('Rejected Deferred'));

        $this->assertSame(null, $eventLoop->wait($eventLoop->async($generatorWaitALittle())));
    }
}
