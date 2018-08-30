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

    public function testReadableStream($expectedSequence = '')
    {
        // Never wait…
        parent::testReadableStream('W0W12345W6R01R23R45R6R');
    }
}
