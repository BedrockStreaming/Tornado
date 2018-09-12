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

        $this->assertEquals(
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

        $this->assertEquals(
            [],
            $eventLoop->wait($promise)
        );
    }

    public function testPromiseAllShouldPreserveTheOrderOfArrayWhenResolvingAsyncPromises()
    {
        $eventLoop = $this->createEventLoop();
        $deferred = $eventLoop->deferred();

        $eventLoop->async((function () use ($deferred, $eventLoop) {
            // Wait some ticks before to resolve the promise
            yield $eventLoop->idle();
            yield $eventLoop->idle();
            $deferred->resolve(2);
        })()
        );

        $promise = $eventLoop->promiseAll(
            $eventLoop->promiseFulfilled(1),
            $deferred->getPromise(),
            $eventLoop->promiseFulfilled(3)
        );

        $this->assertEquals(
            [1, 2, 3],
            $eventLoop->wait($promise)
        );
    }
}
