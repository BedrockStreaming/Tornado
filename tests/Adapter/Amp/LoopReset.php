<?php

namespace M6WebTest\Tornado\Adapter\Amp;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;

// From https://github.com/amphp/phpunit-util/blob/master/src/LoopReset.php
class LoopReset implements TestListener
{
    use TestListenerDefaultImplementation;

    public function endTest(Test $test, float $time): void
    {
    }
}
