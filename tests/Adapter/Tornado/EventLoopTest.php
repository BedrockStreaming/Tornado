<?php

namespace M6WebTest\Tornado\Adapter\Tornado;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Adapter\Tornado;

class EventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Tornado\EventLoop();
    }
}
