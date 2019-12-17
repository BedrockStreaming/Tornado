<?php

namespace M6WebTest\Tornado;

abstract class CancellableEventLoopTest extends EventLoopTest
{
    use EventLoopTest\CancellationTest;

    const LONG_WAITING_TIME = 10000;
}
