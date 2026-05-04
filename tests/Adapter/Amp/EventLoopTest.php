<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Amp;

use M6Web\Tornado\Adapter\Amp;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\EventLoopTestCase;

class EventLoopTest extends EventLoopTestCase
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

    protected function tearDown(): void
    {
        gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop
    }
}
