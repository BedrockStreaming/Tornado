<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Promise;
use M6Web\Tornado\Deferred;
use Swoole\Coroutine;

final class Promise implements Promise, Deferred
{

    public function getPromise(): Promise
    {
        return $this;
    }

    public function resolve($value): void
    {

    }

    public function reject(\Throwable $throwable): void
    {

    }
}
