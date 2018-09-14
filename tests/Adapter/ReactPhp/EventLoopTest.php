<?php

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

    public function testStreamShouldReadFromWritable($expectedSequence = '')
    {
        // Because ReactPhp resolve promise in a slightly different order.
        parent::testStreamShouldReadFromWritable('W0R0W12345R12W6R34R56R');
    }
}
