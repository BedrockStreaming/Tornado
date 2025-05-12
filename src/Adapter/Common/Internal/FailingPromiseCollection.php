<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Common\Internal;

use M6Web\Tornado;

/**
 * @internal
 * ⚠️ This class is not a part of the public interface of Tornado
 */
class FailingPromiseCollection
{
    /**
     * @param Tornado\Promise<mixed> $promise
     */
    public function watchFailingPromise(Tornado\Promise $promise, \Throwable $throwable): void
    {
        $this->registeredThrowables->attach($promise, $throwable);
    }

    /**
     * @param Tornado\Promise<mixed> $promise
     */
    public function unwatchPromise(Tornado\Promise $promise): void
    {
        $this->registeredThrowables->detach($promise);
    }

    public function throwIfWatchedFailingPromiseExists(): void
    {
        if (count($this->registeredThrowables) === 0) {
            return;
        }

        $this->registeredThrowables->rewind();
        throw $this->registeredThrowables->getInfo();
    }

    public function __construct()
    {
        $this->registeredThrowables = new \SplObjectStorage();
    }

    /** @var \SplObjectStorage<Tornado\Promise<mixed>, \Throwable> */
    private $registeredThrowables;
}
