<?php

declare(strict_types=1);

namespace M6WebTest\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use PHPUnit\Framework\TestCase;

abstract class EventLoopTestCase extends TestCase
{
    use EventLoopTest\AsyncTestTrait;
    use EventLoopTest\StreamsTestTrait;
    use EventLoopTest\PromiseAllTestTrait;
    use EventLoopTest\PromiseForeachTestTrait;
    use EventLoopTest\PromiseRaceTestTrait;

    abstract protected function createEventLoop(): EventLoop;

    public function testFulfilledPromise(): void
    {
        $expectedValue = 1664;

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseFulfilled($expectedValue);

        $this->assertSame(
            $expectedValue,
            $eventLoop->wait($promise)
        );
    }

    public function testRejectedPromise(): void
    {
        $expectedException = new class extends \Exception {
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseRejected($expectedException);

        $this->expectException($expectedException::class);
        $eventLoop->wait($promise);
    }

    public function testIdle(string $expectedSequence = 'ABCABA'): void
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

        $this->assertSame([null, null, null], $eventLoop->wait($promise));
        $this->assertSame($expectedSequence, $outputBuffer);
    }

    public function testDelay(): void
    {
        $expectedDelay = 42; /* ms */
        $eventLoop = $this->createEventLoop();

        $promise = $eventLoop->delay($expectedDelay);
        $start = microtime(true);
        $result = $eventLoop->wait($promise);
        $duration = (microtime(true) - $start) * 1000;

        $this->assertSame(null, $result);
        // Can be a little sooner
        $this->assertGreaterThanOrEqual($expectedDelay - 5, $duration);
        // In these conditions, we should be very close of the expected delay
        $this->assertLessThanOrEqual($expectedDelay + 10, $duration);
    }

    public function testDeferredResolved(): void
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
        $this->assertSame(
            $expectedValue,
            $eventLoop->wait(
                $eventLoop->async(
                    $waitingGenerator($deferred->getPromise())
                )
            )
        );
    }

    public function testDeferredRejected(): void
    {
        $expectedException = new class extends \Exception {
        };
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred();

        $resolverGenerator = function (EventLoop $eventLoop, Deferred $deferred) use ($expectedException): \Generator {
            for ($count = 0; $count < 10; $count++) {
                yield $eventLoop->idle();
            }

            $deferred->reject($expectedException);
        };
        $waitingGenerator = fn (Promise $promise): \Generator => yield $promise;

        $eventLoop->async($resolverGenerator($eventLoop, $deferred));
        $promise = $eventLoop->async($waitingGenerator($deferred->getPromise()));

        $this->expectException($expectedException::class);
        $eventLoop->wait($promise);
    }

    public function testWaitFunctionShouldReturnAsSoonAsPromiseIsResolved(): void
    {
        $eventLoop = $this->createEventLoop();
        $count = 0;
        $unfinishedGenerator = function (EventLoop $eventLoop, int &$count): \Generator {
            while (++$count < 10) {
                yield $eventLoop->idle();
            }
        };

        $eventLoop->async($unfinishedGenerator($eventLoop, $count));
        $result = $eventLoop->wait($eventLoop->promiseFulfilled('value'));

        $this->assertSame('value', $result);
        $this->assertLessThanOrEqual(2, $count);
    }

    public function testWaitFunctionShouldThrowIfPromiseCannotBeResolved(): void
    {
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred();

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Impossible to resolve the promise, no more task to execute.');
        $eventLoop->wait($deferred->getPromise());
    }

    public function testExceptionBeforeYieldAreCatchable(): void
    {
        $eventLoop = $this->createEventLoop();

        $failingPromise = $eventLoop->async((function () use ($eventLoop): \Generator {
            throw new \Exception('This is a failure');
            yield $eventLoop->idle(); // @phpstan-ignore deadCode.unreachable (Mandatory if we want to create a generator)
        })());

        $createGenerator = function () use ($failingPromise): \Generator {
            try {
                yield $failingPromise;

                return 'not catched :(';
            } catch (\Exception) {
                return 'catched!';
            }
        };

        $this->assertSame(
            'catched!',
            $eventLoop->wait($eventLoop->async($createGenerator()))
        );
    }
}
