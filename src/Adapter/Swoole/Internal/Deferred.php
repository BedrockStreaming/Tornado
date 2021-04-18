<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /** @var SwooleDeferred */
    private $swooleDeferred;

    /** @var PromiseWrapper */
    private $promise;

    public function __construct(SwooleDeferred $swooleDeferred, PromiseWrapper $promiseWrapper)
    {
        $this->swooleDeferred = $swooleDeferred;
        $this->promise = $promiseWrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    public function getPromiseWrapper(): PromiseWrapper
    {
        return $this->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($value): void
    {
        $this->swooleDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable): void
    {
        $this->swooleDeferred->reject($throwable);
    }
}
