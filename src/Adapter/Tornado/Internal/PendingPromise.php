<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PendingPromise
{
    private $value;
    private $throwable;
    private $callbacks = [];
    private $isLocked = false;

    public function resolve($value): self
    {
        $this->lock();
        $this->value = $value;

        return $this->triggerCallbacks();
    }

    public function reject(\Throwable $throwable): self
    {
        $this->lock();
        $this->throwable = $throwable;

        return $this->triggerCallbacks();
    }

    public function addCallbacks(callable $onSuccess, callable $onFailure): self
    {
        $this->callbacks[] = [$onSuccess, $onFailure];

        return $this->triggerCallbacks();
    }

    private function triggerCallbacks(): self
    {
        if ($this->isLocked) {
            if ($this->throwable !== null) {
                foreach ($this->callbacks as [$onSuccess, $onFailure]) {
                    $onFailure($this->throwable);
                }
            } else {
                foreach ($this->callbacks as [$onSuccess, $onFailure]) {
                    $onSuccess($this->value);
                }
            }
        }

        return $this;
    }

    private function lock()
    {
        if ($this->isLocked) {
            throw new \LogicException('Cannot resolve/reject a promise already settled.');
        }

        $this->isLocked = true;
    }
}
