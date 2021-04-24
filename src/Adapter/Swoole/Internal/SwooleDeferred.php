<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
final class SwooleDeferred implements Deferred
{
    private $promise;
    private $resolveCallback;
    private $rejectCallback;

    public function __construct()
    {
    }

    public function getPromise(): Promise
    {
        return $this->getSwoolePromise();
    }

    public function getSwoolePromise(): SwoolePromise
    {
        if (null === $this->promise) {
            $this->promise = new SwoolePromise(function ($resolve, $reject) {
                $this->resolveCallback = $resolve;
                $this->rejectCallback = $reject;
            });
        }

        return $this->promise;
    }

    public function resolve($value): void
    {
        $this->getPromise();

        \call_user_func($this->resolveCallback, $value);
    }

    public function reject(\Throwable $throwable): void
    {
        $this->getPromise();

        \call_user_func($this->rejectCallback, $throwable);
    }
}
