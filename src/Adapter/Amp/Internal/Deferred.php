<?php

declare(strict_types=1);

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
    /**
     * @param \Amp\Deferred<TValue>  $ampDeferred
     * @param PromiseWrapper<TValue> $promise
     */
    public function __construct(private readonly \Amp\Deferred $ampDeferred, private readonly PromiseWrapper $promise)
    {
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
