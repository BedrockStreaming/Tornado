<?php

namespace M6Test\Front\Async\Adapter\Synchronous;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Adapter\Synchronous;

class EventLoopTest extends \M6Test\Front\Async\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Synchronous\EventLoop();
    }

    public function testIdle($expectedSequence = '')
    {
        //By definition, this is not an asynchronous EventLoop
        parent::testIdle('AAABBC');
    }
}
