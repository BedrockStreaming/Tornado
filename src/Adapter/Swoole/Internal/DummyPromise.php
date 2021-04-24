<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Throwable;

final class DummyPromise implements Promise, Deferred
{
    /**
     * @var bool
     */
    private $isPending;

    /**
     * @var mixed
     */
    private $value;

    /**
     * @var Throwable|null
     */
    private $exception;

    /**
     * @var callable[]
     */
    private $callbacks;

    public function __construct(?callable $callback = null)
    {
        $this->isPending = true;
        $this->exception = null;
        $this->callbacks = $callback ? [$callback] : [];
    }

    public static function wrap(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        return $promise;
    }

    public function getPromise(): Promise
    {
        return $this;
    }

    public function addCallback(callable $callback)
    {
        $this->callbacks[] = $callback;
    }

    public function resolve($value): void
    {
        assert(true === $this->isPending, new \Error('Promise is already resolved.'));

        $this->isPending = false;
        $this->value = $value;

        foreach ($this->callbacks as $callback) {
            ($callback)($this);
        }
    }

    public function reject(Throwable $throwable): void
    {
        assert(true === $this->isPending, new \Error('Promise is already resolved.'));

        $this->isPending = false;
        $this->exception = $throwable;

        foreach ($this->callbacks as $callback) {
            ($callback)($this);
        }
    }

    public function isPending(): bool
    {
        return $this->isPending;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getException(): ?Throwable
    {
        return $this->exception;
    }
}
