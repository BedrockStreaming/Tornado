<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /** @var \Amp\Deferred<TValue> */
    private $ampDeferred;

    /** @var PromiseWrapper<TValue> */
    private $promise;

    /**
     * @param \Amp\Deferred<TValue>  $ampDeferred
     * @param PromiseWrapper<TValue> $promise
     */
    public function __construct(\Amp\Deferred $ampDeferred, PromiseWrapper $promise)
    {
        $this->ampDeferred = $ampDeferred;
        $this->promise = $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * @return PromiseWrapper<TValue>
     */
    public function getPromiseWrapper(): PromiseWrapper
    {
        return $this->promise;
    }

    /**
     * @param TValue $value
     */
    public function resolve($value): void
    {
        $this->ampDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable): void
    {
        $this->ampDeferred->fail($throwable);
    }
}
