<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;

trait PromiseAllTest
{
    abstract protected function createEventLoop(): EventLoop;

    public function testAllPromisesFulfilled()
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

    public function testAllPromisesRejected()
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
}
