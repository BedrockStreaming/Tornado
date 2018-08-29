<?php

namespace M6WebTest\Tornado\Adapter\Amp;

use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\Test;

// From https://github.com/amphp/phpunit-util/blob/master/src/LoopReset.php
class LoopReset implements TestListener
{
    use TestListenerDefaultImplementation;

    public function endTest(Test $test, float $time): void
    {
        \Amp\Loop::set((new \Amp\Loop\DriverFactory())->create());
        gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop
    }
}
