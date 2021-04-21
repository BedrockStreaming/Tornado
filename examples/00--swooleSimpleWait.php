#!/usr/bin/env php
<?php

namespace M6WebExamples\Tornado;

use Swoole\Coroutine;
use Swoole\Event;
use function Swoole\Coroutine\batch;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\WaitGroup;

require __DIR__.'/../vendor/autoload.php';

class Promise
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

    public function resolve($value): void
    {
        echo "resolving with $value\n";
        assert(false === $this->isSettled);
        $this->isSettled = true;
        $this->value = $value;
        foreach ($this->cids as $cid => $dummy) {
            Coroutine::resume($cid);
        }
        $this->cids = [];
    }

    public function value()
    {
        assert($this->isSettled);
        return $this->value;
    }
};

function async(\Generator $generator): Promise {
    $generatorPromise = new Promise();
    Coroutine::create(function() use($generator, $generatorPromise) {
        while($generator->valid()) {
            echo "Promise found\n";
            $promise = $generator->current();
            $promise->yield();
            $generator->send($promise->value());
        }

        $generatorPromise->resolve($generator->getReturn());
    });

    return $generatorPromise;
}

function delay(int $ms) {
    $promise = new Promise();
    Coroutine::create(function() use($ms, $promise) {
        Coroutine::sleep($ms / 1000 /* ms -> s */);
        $promise->resolve(null);
    });

    return $promise;
}

function wait(Promise $promise) {

    $value = null;
    async((function() use($promise, &$value) {
        echo "wait\n";
        $value = yield $promise;
        Event::exit();
    })());
    Event::wait();

    return $value;
}

function generator(string $name, int $delay): \Generator {
    echo "[$name] Waiting $delay ms…\n";
    yield delay($delay);
    echo "[$name] Done!\n";

    return "[$name] Finished\n";
}

$result = wait(async(generator('A', 2000)));
echo "Result: $result\n";