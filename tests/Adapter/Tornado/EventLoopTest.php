<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Tornado;

use M6Web\Tornado\Adapter\Tornado;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\EventLoopTestCase;

class EventLoopTest extends EventLoopTestCase
{
    protected function createEventLoop(): EventLoop
    {
        return new Tornado\EventLoop();
    }
}
