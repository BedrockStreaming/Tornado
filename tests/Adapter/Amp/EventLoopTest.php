<?php

namespace M6Test\Front\Async\Adapter\Amp;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Adapter\Amp;

class EventLoopTest extends \M6Test\Front\Async\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Amp\EventLoop();
    }
}
