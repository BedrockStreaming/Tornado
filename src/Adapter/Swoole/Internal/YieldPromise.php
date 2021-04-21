<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Promise;
use M6Web\Tornado\Deferred;
use Swoole\Coroutine;

final class YieldPromise implements Promise, Deferred
{
    private $cids = [];
    private $isSettled = false;
    private $value;

    public function yield(): void
    {
        if($this->isSettled) {
            return;
        }

        $this->cids[Coroutine::getCid()] = true;
        Coroutine::yield();
    }

    public function value()
    {
        assert($this->isSettled, new \Error('Promise is not resolved.'));
        return $this->value;
    }

    public function getPromise(): YieldPromise
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

    }
}
