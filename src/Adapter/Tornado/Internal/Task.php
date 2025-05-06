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
    /**
     * @param \Generator<int, Promise> $generator
     * @param PendingPromise<TValue> $promise
     */
    public function __construct(
        private readonly \Generator $generator,
        private readonly PendingPromise $promise
    ) {
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
