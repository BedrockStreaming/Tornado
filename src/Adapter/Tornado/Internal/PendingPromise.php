<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PendingPromise implements Promise
{
    private $value;
    private $throwable;
    private $callbacks = [];
    private $isSettled = false;
    private $hasBeenYielded = false;

    public static function downcast(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        return $promise;
    }

    public static function fromGenerator(\Generator $generator): self
    {
        $promise = $generator->current();
        if (!$promise instanceof self) {
            throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
        }

        $promise = self::downcast($promise);
        $promise->hasBeenYielded = true;

        return $promise;
    }

    public function hasBeenYielded(): bool
    {
        return $this->hasBeenYielded;
    }

    public function resolve($value): self
    {
        $this->settle();
        $this->value = $value;

        return $this->triggerCallbacks();
    }

    public function reject(\Throwable $throwable): self
    {
        $this->settle();
        $this->throwable = $throwable;

        return $this->triggerCallbacks();
    }

    public function addCallbacks(callable $onResolved, callable $onRejected): self
    {
        $this->callbacks[] = [$onResolved, $onRejected];

        return $this->isSettled ? $this->triggerCallbacks() : $this;
    }

    private function triggerCallbacks(): self
    {
        if ($this->throwable !== null) {
            foreach ($this->callbacks as [, $onRejected]) {
                $onRejected($this->throwable);
            }
        } else {
            foreach ($this->callbacks as [$onResolved]) {
                $onResolved($this->value);
            }
        }
        // Callbacks must be triggered only once!
        $this->callbacks = [];

        return $this;
    }

    private function settle()
    {
        if ($this->isSettled) {
            throw new \LogicException('Cannot resolve/reject a promise already settled.');
        }

        $this->isSettled = true;
    }
}
