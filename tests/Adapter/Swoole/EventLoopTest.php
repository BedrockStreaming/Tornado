<?php

namespace M6WebTest\Tornado\Adapter\Swoole;

use M6Web\Tornado\Adapter\Swoole;
use M6Web\Tornado\EventLoop;

class EventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new Swoole\EventLoop();
    }
}