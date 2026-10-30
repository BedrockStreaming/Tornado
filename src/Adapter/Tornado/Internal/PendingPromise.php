<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 *
 * @implements Promise<TValue>
 */
class PendingPromise implements Promise, Deferred
{
    /** @var TValue */
    private $value;
    /** @var ?\Throwable */
    private $throwable;
    /** @var list<array{callable, callable}|\Closure(?\Throwable, mixed): void> */
    private $callbacks = [];
    /** @var bool */
    private $isSettled = false;
    /** @var ?FailingPromiseCollection */
    private $failingPromiseCollection;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    public static function createUnhandled(FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self();
        $promiseWrapper->failingPromiseCollection = $failingPromiseCollection;

        return $promiseWrapper;
    }

    public static function createHandled(): self
    {
        $promiseWrapper = new self();
        $promiseWrapper->failingPromiseCollection = null;

        return $promiseWrapper;
    }

    /**
     * @param Promise<TValue> $promise
     *
     * @return self<TValue>
     */
    public static function toHandledPromise(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        if ($promise->failingPromiseCollection !== null) {
            // Only rejected promises are watched.
            if ($promise->throwable !== null) {
                $promise->failingPromiseCollection->unwatchPromise($promise);
            }
            $promise->failingPromiseCollection = null;
        }

        return $promise;
    }

    /**
     * @param TValue $value
     */
    public function resolve($value): void
    {
        $this->settle();
        $this->value = $value;

        $this->triggerCallbacks();
    }

    public function reject(\Throwable $throwable): void
    {
        $this->settle();
        $this->throwable = $throwable;

        if ($this->failingPromiseCollection !== null) {
            $this->failingPromiseCollection->watchFailingPromise($this, $throwable);
        }

        $this->triggerCallbacks();
    }

    /**
     * @return Promise<TValue>
     */
    public function getPromise(): Promise
    {
        return $this;
    }

    public function isSettled(): bool
    {
        return $this->isSettled;
    }

    /**
     * @return TValue
     */
    public function getValue()
    {
        return $this->value;
    }

    public function getThrowable(): ?\Throwable
    {
        return $this->throwable;
    }

    public function addCallbacks(callable $onResolved, callable $onRejected): self
    {
        $this->callbacks[] = [$onResolved, $onRejected];

        return $this->isSettled ? $this->triggerCallbacks() : $this;
    }

    /**
     * Same as addCallbacks, with a single callback receiving the throwable (null on success) and the value.
     *
     * @param \Closure(?\Throwable, mixed): void $callback
     */
    public function onSettle(\Closure $callback): self
    {
        $this->callbacks[] = $callback;

        return $this->isSettled ? $this->triggerCallbacks() : $this;
    }

    private function triggerCallbacks(): self
    {
        foreach ($this->callbacks as $callback) {
            if ($callback instanceof \Closure) {
                $callback($this->throwable, $this->value);
            } elseif ($this->throwable !== null) {
                $callback[1]($this->throwable);
            } else {
                $callback[0]($this->value);
            }
        }
        // Callbacks must be triggered only once!
        $this->callbacks = [];

        return $this;
    }

    private function settle(): void
    {
        if ($this->isSettled) {
            throw new \LogicException('Cannot resolve/reject a promise already settled.');
        }

        $this->isSettled = true;
    }
}
