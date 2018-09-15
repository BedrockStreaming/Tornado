<?php

namespace M6WebTest\Tornado\Adapter\Tornado;

use M6Web\Tornado\Adapter\Tornado;
use M6Web\Tornado\EventLoop;

class SynchronousEventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Tornado\SynchronousEventLoop();
    }

    public function testIdle($expectedSequence = '')
    {
        //By definition, this is not an asynchronous EventLoop
        parent::testIdle('AAABBC');
    }

    public function testPromiseRaceShouldResolvePromisesArray(int $expectedValue = 2)
    {
        // In the synchronous case, there is no race, first promise always win
        parent::testPromiseRaceShouldResolvePromisesArray(1);
    }

    public function testPromiseRaceShouldRejectIfFirstSettledPromiseRejects(int $expectedValue = 2)
    {
        // In the synchronous case, there is no race, first promise always win
        parent::testPromiseRaceShouldRejectIfFirstSettledPromiseRejects(1);
    }

    public function testStreamShouldReadFromWritable($expectedSequence = '')
    {
        // Never wait…
        parent::testStreamShouldReadFromWritable('W0W12345W6R01R23R45R6R');
    }

    public function testDelay()
    {
        $expectedDelay = 42; /*ms*/
        $eventLoop = $this->createEventLoop();

        // For synchronous event loop, the delay is applied as soon as requested!
        $start = microtime(true);
        $promise = $eventLoop->delay($expectedDelay);
        $duration = (microtime(true) - $start) * 1000;
        $result = $eventLoop->wait($promise);

        $this->assertSame(null, $result);
        // Can be a little sooner
        $this->assertGreaterThanOrEqual($expectedDelay - 5, $duration);
        // In these conditions, we should be close of the expected delay
        $this->assertLessThanOrEqual($expectedDelay + 10, $duration);
    }
}
