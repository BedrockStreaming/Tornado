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
}
