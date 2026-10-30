<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Amp\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 *
 * @implements Promise<TValue>
 */
final class PromiseWrapper implements Promise
{
    private bool $isSettled = false;
    private mixed $value = null;
    private ?\Throwable $error = null;
    private bool $isWatched = false;
    /** @var list<\Closure(?\Throwable, mixed): void> */
    private array $callbacks = [];
    private ?DeferredFuture $ampDeferred = null;

    /**
     * Use named (static) constructor instead
     */
    private function __construct(
        private bool $isHandled,
        private readonly ?FailingPromiseCollection $failingPromiseCollection,
    ) {
    }

    /**
     * @return self<TValue>
     */
    public static function createUnhandled(FailingPromiseCollection $failingPromiseCollection): self
    {
        return new self(false, $failingPromiseCollection);
    }

    /**
     * @return self<TValue>
     */
    public static function createHandled(): self
    {
        return new self(true, null);
    }

    /**
     * @return self<TValue>
     */
    public static function createFulfilled(mixed $value): self
    {
        $promise = new self(true, null);
        $promise->settle(value: $value);

        return $promise;
    }

    /**
     * @param Promise<TValue> $promise
     *
     * @return self<TValue>
     */
    public static function toHandledPromise(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        if ($promise->isWatched) {
            $promise->isWatched = false;
            $promise->failingPromiseCollection?->unwatchPromise($promise);
        }

        return $promise;
    }

    /**
     * @param \Closure(?\Throwable, mixed): void $callback
     */
    public function onSettle(\Closure $callback): void
    {
        if ($this->isSettled) {
            $callback($this->error, $this->value);

            return;
        }

        $this->callbacks[] = $callback;
    }

    /**
     * @return Future<TValue>
     */
    public function getAmpFuture(): Future
    {
        if ($this->isSettled) {
            return $this->error === null ? Future::complete($this->value) : Future::error($this->error);
        }

        $this->ampDeferred ??= new DeferredFuture();

        return $this->ampDeferred->getFuture();
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function isSettled(): bool
    {
        return $this->isSettled;
    }

    public function settle(?\Throwable $error = null, mixed $value = null): void
    {
        if ($this->isSettled) {
            throw new \Error('Promise already settled.');
        }

        $this->isSettled = true;
        $this->error = $error;
        $this->value = $value;

        if ($error !== null && !$this->isHandled && $this->failingPromiseCollection !== null) {
            $this->isWatched = true;
            $this->failingPromiseCollection->watchFailingPromise($this, $error);
        }

        if ($this->ampDeferred !== null) {
            $error === null ? $this->ampDeferred->complete($value) : $this->ampDeferred->error($error);
        }

        $callbacks = $this->callbacks;
        $this->callbacks = [];
        foreach ($callbacks as $callback) {
            $callback($error, $value);
        }
    }
}
