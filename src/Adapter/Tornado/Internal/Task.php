<?php

namespace M6Web\Tornado\Adapter\Tornado\Internal;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Task
{
    private $generator;
    private $promise;

    public function __construct(\Generator $generator, PendingPromise $pendingPromise)
    {
        $this->generator = $generator;
        $this->promise = $pendingPromise;
    }

    public function getPromise(): PendingPromise
    {
        return $this->promise;
    }

    public function getGenerator(): \Generator
    {
        return $this->generator;
    }
}
