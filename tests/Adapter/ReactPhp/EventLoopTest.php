<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\ReactPhp;

use M6Web\Tornado\Adapter\ReactPhp;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\EventLoopTestCase;
use React\EventLoop\StreamSelectLoop;

class EventLoopTest extends EventLoopTestCase
{
    protected function createEventLoop(): EventLoop
    {
        return new ReactPhp\EventLoop(new StreamSelectLoop());
    }
}
