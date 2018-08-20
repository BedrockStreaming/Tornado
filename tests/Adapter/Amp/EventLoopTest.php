<?php

namespace M6Test\Front\Async\Adapter\Amp;

use M6\Front\Async\EventLoop;
use M6\Front\Async\Adapter\Amp;

class EventLoopTest extends \M6Test\Front\Async\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Amp\EventLoop();
    }
}
