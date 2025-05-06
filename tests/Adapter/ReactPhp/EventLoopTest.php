<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\ReactPhp;

use M6Web\Tornado\Adapter\ReactPhp;
use M6Web\Tornado\EventLoop;
use React\EventLoop\StreamSelectLoop;

class EventLoopTest extends \M6WebTest\Tornado\EventLoopTest
{
    protected function createEventLoop(): EventLoop
    {
        return new ReactPhp\EventLoop(new StreamSelectLoop());
    }
}
