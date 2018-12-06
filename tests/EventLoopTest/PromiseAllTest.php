<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;

trait PromiseAllTest
{
    abstract protected function createEventLoop(): EventLoop;

    public function testPromiseAllShouldResolvePromisesArray()
    {
        $expectedValues = [1, 'ok', new \stdClass(), ['array']];

        $eventLoop = $this->createEventLoop();
        $promises = array_map([$eventLoop, 'promiseFulfilled'], $expectedValues);
        $promise = $eventLoop->promiseAll(...$promises);

        $this->assertSame(
            $expectedValues,
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseAllShouldRejectIfAnyInputPromiseRejects()
    {
        $expectedException = new class() extends \Exception {
        };

        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseAll(
            $eventLoop->promiseFulfilled(1),
            $eventLoop->promiseRejected($expectedException),
            $eventLoop->promiseFulfilled(2),
            $eventLoop->promiseRejected(new \Exception())
        );

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    public function testPromiseAllShouldResolveEmptyInput()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseAll();

        $this->assertSame(
            [],
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseAllShouldPreserveTheOrderOfArrayWhenResolvingAsyncPromises()
    {
        $eventLoop = $this->createEventLoop();

        $promise2 = $eventLoop->async((function () use ($eventLoop) {
            // Wait some ticks before to resolve the promise
            yield $eventLoop->idle();
            yield $eventLoop->idle();

            return 2;
        })()
        );

        $promise = $eventLoop->promiseAll(
            $eventLoop->promiseFulfilled(1),
            $promise2,
            $eventLoop->promiseFulfilled(3)
        );

        $this->assertSame(
            [1, 2, 3],
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseAllCatchableException()
    {
        $eventLoop = $this->createEventLoop();

        $throwingGenerator = (function () use ($eventLoop): \Generator {
            yield $eventLoop->idle();
            throw new \Exception('This is a failure');
        })();

        $createGenerator = function () use ($eventLoop, $throwingGenerator): \Generator {
            try {
                yield $eventLoop->promiseAll($eventLoop->async($throwingGenerator));
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
