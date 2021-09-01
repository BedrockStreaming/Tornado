<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;

trait PromiseRaceTest
{
    abstract protected function createEventLoop(): EventLoop;

    public function testPromiseRaceShouldResolveEmptyInput(): void
    {
        $eventLoop = $this->createEventLoop();

        $promise = $eventLoop->promiseRace();

        $this->assertSame(
            null,
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseRaceShouldResolvePromisesArray(int $expectedValue = 2): void
    {
        $eventLoop = $this->createEventLoop();
        $d1 = $eventLoop->deferred();
        $d2 = $eventLoop->deferred();
        $d3 = $eventLoop->deferred();

        // $d2 will be resolved first
        $eventLoop->async((function () use ($d1, $d2, $d3, $eventLoop) {
            // Wait some ticks before to resolve the promise
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            $d2->resolve(2);

            // Then, resolve other promises
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            $d1->resolve(1);
            $d3->resolve(3);
        })()
        );

        $promise = $eventLoop->promiseRace(
            $d1->getPromise(),
            $d2->getPromise(),
            $d3->getPromise()
        );

        $this->assertSame(
            $expectedValue,
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseRaceShouldRejectIfFirstSettledPromiseRejects(int $expectedValue = 2): void
    {
        $eventLoop = $this->createEventLoop();
        $d1 = $eventLoop->deferred();
        $d2 = $eventLoop->deferred();
        $d3 = $eventLoop->deferred();

        // $d2 will be rejected first
        $eventLoop->async((function () use ($d1, $d2, $d3, $eventLoop) {
            // Wait some ticks before to resolve the promise
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            $d2->reject(new \Exception('', 2));

            // Then, resolve other promises
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            $d1->reject(new \Exception('', 1));
            $d3->reject(new \Exception('', 3));
        })()
        );

        $promise = $eventLoop->promiseRace(
            $d1->getPromise(),
            $d2->getPromise(),
            $d3->getPromise()
        );

        $this->expectExceptionCode($expectedValue);
        $eventLoop->wait($promise);
    }

    public function testPromiseRaceCatchableException(): void
    {
        $eventLoop = $this->createEventLoop();

        $throwingGenerator = (function () use ($eventLoop): \Generator {
            yield $eventLoop->idle();
            throw new \Exception('This is a failure');
        })();

        $createGenerator = function () use ($eventLoop, $throwingGenerator): \Generator {
            try {
                yield $eventLoop->promiseRace(
                    $eventLoop->async($throwingGenerator),
                    $eventLoop->delay(1000)
                );

                return 'Not catched :(';
            } catch (\Exception $e) {
                return 'catched!';
            }
        };

        $this->assertSame(
            'catched!',
            $eventLoop->wait($eventLoop->async($createGenerator()))
        );
    }
}
