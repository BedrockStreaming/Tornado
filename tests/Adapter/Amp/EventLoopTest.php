<?php

namespace M6WebTest\Tornado\Adapter\Amp;

use M6Web\Tornado\Adapter\Amp;
use M6Web\Tornado\EventLoop;

class EventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Amp\EventLoop();
    }
}
