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

    public function testStreamShouldReadFromWritable($expectedSequence = '')
    {
        // Because AMP doesn't trigger write callback immediately
        parent::testStreamShouldReadFromWritable('W0R0W12345R12R34R5W6R6R');
    }
}
