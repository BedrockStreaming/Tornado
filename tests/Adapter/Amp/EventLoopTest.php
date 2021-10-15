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

    public function testStreamShouldReadFromWritable(string $expectedSequence = ''): void
    {
        // Because Amp resolve promises in a slightly different order.
        parent::testStreamShouldReadFromWritable('W0R0W12345R12R34W6R56R');
    }
}
