<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /**
     * @var PendingPromise
     */
    private $promise;

    public function __construct()
    {
        $this->promise = new PendingPromise();
    }

    public function getPromise(): Promise
    {
        return $this->promise;
    }

    public function resolve($value)
    {
        $this->promise->resolve($value);
    }

    public function reject(\Throwable $throwable)
    {
        $this->promise->reject($throwable);
    }
}
