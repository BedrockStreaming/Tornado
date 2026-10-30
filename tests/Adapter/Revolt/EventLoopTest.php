<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Revolt;

use M6Web\Tornado\Adapter\Revolt;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\EventLoopTestCase;

class EventLoopTest extends EventLoopTestCase
{
    protected function createEventLoop(): EventLoop
    {
        return new Revolt\EventLoop();
    }

    public function testStreamShouldReadFromWritable(string $expectedSequence = ''): void
    {
        // Because Revolt resolve promises in a slightly different order, like Amp.
        parent::testStreamShouldReadFromWritable('W0R0W12345R12R34W6R56R');
    }

    protected function tearDown(): void
    {
        // Revolt's driver is global and cannot be swapped once run: drop callbacks left over by unfinished tasks.
        foreach (\Revolt\EventLoop::getIdentifiers() as $callbackId) {
            \Revolt\EventLoop::cancel($callbackId);
        }
        gc_collect_cycles();
    }
}
