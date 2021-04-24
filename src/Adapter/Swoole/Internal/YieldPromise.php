<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Swoole\Coroutine;

final class YieldPromise implements Promise, Deferred
{
    private $cids;
    private $isSettled;
    private $value;
    private $exception;

    public function __construct()
    {
        $this->cids = [];
        $this->isSettled = false;
    }

    public function yield(): void
    {
        if ($this->isSettled) {
            return;
        }

        $this->cids[Coroutine::getCid()] = true;
        Coroutine::yield();
    }

    public function value()
    {
        assert($this->isSettled, new \Error('Promise is not resolved.'));

        if ($this->exception) {
            return $this->exception;
        }

        return $this->value;
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
        assert(false === $this->isSettled, new \Error('Promise is already resolved.'));

        $this->isSettled = true;
        $this->value = $value;
        foreach ($this->cids as $cid => $dummy) {
            Coroutine::resume($cid);
        }
        $this->cids = [];
    }

    public function reject(\Throwable $throwable): void
    {
        assert(false === $this->isSettled, new \Error('Promise is already resolved.'));

        $this->isSettled = true;
        $this->exception = $throwable;
        foreach ($this->cids as $cid => $dummy) {
            Coroutine::resume($cid);
        }
        $this->cids = [];
    }
}
