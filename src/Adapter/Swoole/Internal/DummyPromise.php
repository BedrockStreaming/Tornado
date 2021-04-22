<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Promise;
use M6Web\Tornado\Deferred;
use Throwable;

final class DummyPromise implements Promise, Deferred
{
    private bool $isPending;
    private mixed $value;
    private ?Throwable $exception;
    private $callback;

    public function __construct(?callable $callback = null)
    {
        $this->isPending = true;
        $this->exception = null;
        $this->callback = $callback ?? static function() {};
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

    public function resolve($value): void
    {
        assert(true === $this->isPending, new \Error('Promise is already resolved.'));

        $this->isPending = false;
        $this->value = $value;
        ($this->callback)();
    }

    public function reject(Throwable $throwable): void
    {
        assert(true === $this->isPending, new \Error('Promise is already resolved.'));

        $this->isPending = false;
        $this->exception = $throwable;
        ($this->callback)();
    }

    public function isPending(): bool
    {
        if($this->isPending) {
            return $this->isPending;
        }

        if($this->exception === null) {
            if ($this->value instanceof self) {
                return $this->value->isPending();
            }

            if(is_array($this->value)) {
                foreach ($this->value as $value) {
                    if($value instanceof self && $value->isPending()) {
                        return $value->isPending();
                    }
                }
            }
        }

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
