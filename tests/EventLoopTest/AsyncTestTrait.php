<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;

trait AsyncTestTrait
{
    abstract protected function createEventLoop(): EventLoop;

    public function testDummyGenerator(): void
    {
        $expectedValue = 42;
        $generator = (function ($value): \Generator {
            return $value; // @phpstan-ignore return.type (it is a generator)
            yield;  // @phpstan-ignore deadCode.unreachable (Mandatory if we want to create a generator)
        })($expectedValue);

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($generator);

        $this->assertSame(
            $expectedValue,
            $eventLoop->wait($promise)
        );
    }

    public function testDummyGeneratorThrowing(): void
    {
        $expectedException = new class extends \Exception {
        };
        $generator = (function ($exception): \Generator {
            throw $exception;
            yield;  // @phpstan-ignore deadCode.unreachable (Mandatory if we want to create a generator)
        })($expectedException);

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($generator);

        $this->expectException($expectedException::class);
        $eventLoop->wait($promise);
    }

    public function testYieldingInvalidValueMayThrowAnError(): void
    {
        $createGenerator = function (): \Generator {
            yield 'Something that is not a promise.';
        };

        $eventLoop = $this->createEventLoop();
        /** @phpstan-ignore-next-line phpstan detects the generator yields a non-promise */
        $promise = $eventLoop->async($createGenerator());

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Asynchronous function is yielding a [string] instead of a Promise.');
        $eventLoop->wait($promise);
    }

    public function testYieldingGenerator(): void
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

    public function testYieldingGeneratorWithRejectedPromise(): void
    {
        $createGenerator = function (EventLoop $eventLoop, \Exception $exception): \Generator {
            try {
                yield $eventLoop->promiseRejected($exception);

                return 'not catched :(';
            } catch (\Throwable $exception) {
                return $exception;
            }
        };
        $expectedException = new class extends \Exception {
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->async($createGenerator($eventLoop, $expectedException));

        $this->assertSame(
            $expectedException,
            $eventLoop->wait($promise)
        );
    }

    public function testSubGenerators(): void
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

    public function testSubGeneratorThrowing(): void
    {
        $eventLoop = $this->createEventLoop();
        $throwingGenerator = function (\Throwable $throwable) use ($eventLoop): \Generator {
            yield $eventLoop->idle();
            throw $throwable;
        };
        $tryCatchGenerator = function (Promise $promise) use ($eventLoop): \Generator {
            try {
                yield $promise;

                return 'Not an error message';
            } catch (\Throwable $throwable) {
                yield $eventLoop->idle();

                return $throwable->getMessage();
            }
        };

        $promise = $eventLoop->async($tryCatchGenerator(
            $eventLoop->async($throwingGenerator(
                new \Exception('Error Message')
            ))
        ));

        $this->assertSame('Error Message', $eventLoop->wait($promise));
    }

    public function testYieldingForTheSameFulfilledPromise(): void
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

    public function testYieldingForTheSameRejectedPromise(): void
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

    public function testEventLoopFirstTick(): void
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

    public function testEventLoopShouldThrowInCaseOfUncaughtExceptionInBackgroundGenerator(): void
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

        $eventLoop->async($failingGenerator());
        $promiseSuccess = $eventLoop->async($waitingGenerator());

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This is a failure');
        $eventLoop->wait($promiseSuccess);
        gc_collect_cycles();
    }

    public function testEventLoopShouldNotThrowInCaseOfExplicitlyRejectedPromise(): void
    {
        $eventLoop = $this->createEventLoop();

        $generatorWaitALittle = function () use ($eventLoop) {
            yield $eventLoop->idle();
            yield $eventLoop->idle();

            return null;
        };

        $unwatchedRejectedPromise = $eventLoop->promiseRejected(new \Exception('Rejected Promise'));
        $unwatchedDeferred = $eventLoop->deferred();
        $unwatchedDeferred->reject(new \Exception('Rejected Deferred'));

        $this->assertSame(null, $eventLoop->wait($eventLoop->async($generatorWaitALittle())));
    }
}
