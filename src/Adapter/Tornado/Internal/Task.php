<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 */
class Task
{
    /** @var \Generator<int, Promise> */
    private $generator;
    /** @var PendingPromise<TValue> */
    private $promise;

    /**
     * @param \Generator<int, Promise> $generator
     * @param PendingPromise<TValue>   $pendingPromise
     */
    public function __construct(\Generator $generator, PendingPromise $pendingPromise)
    {
        $this->generator = $generator;
        $this->promise = $pendingPromise;
    }

    /**
     * @return PendingPromise<TValue>
     */
    public function getPromise(): PendingPromise
    {
        return $this->promise;
    }

    /**
     * @return \Generator<int, Promise>
     */
    public function getGenerator(): \Generator
    {
        return $this->generator;
    }
}
