<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class SynchronousEventLoop implements \M6Web\Tornado\EventLoop
{
    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        // Is it a fulfilled promise
        if (property_exists($promise, 'value')) {
            return $promise->value;
        }

        // Is it a rejected promise?
        if (property_exists($promise, 'exception')) {
            throw $promise->exception;
        }

        throw new \LogicException('Cannot wait a promise not created from the same EventLoop');
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        try {
            while ($generator->valid()) {
                $blockingPromise = $generator->current();

                // Resolves blocking promise and forwards result to the generator
                $blockingPromiseValue = null;
                $blockingPromiseException = null;
                try {
                    $blockingPromiseValue = $this->wait($blockingPromise);
                } catch (\Throwable $exception) {
                    $blockingPromiseException = $exception;
                }
                if ($blockingPromiseException) {
                    $generator->throw($blockingPromiseException);
                } else {
                    $generator->send($blockingPromiseValue);
                }
            }

            return $this->promiseFulfilled($generator->getReturn());
        } catch (\Throwable $exception) {
            return $this->promiseRejected($exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        try {
            return $this->promiseFulfilled(array_map([$this, 'wait'], $promises));
        } catch (\Throwable $exception) {
            return $this->promiseRejected($exception);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        return reset($promises) ?: $this->promiseFulfilled(null);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        $promise = new class() implements Promise {
            public $value;
        };
        $promise->value = $value;

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        $promise = new class() implements Promise {
            public $exception;
        };
        $promise->exception = $throwable;

        return $promise;
    }

    /**
     * {@inheritdoc}
     *
     * Note: Because this loop is synchronous, this is an alias for fullfilledPromise.
     */
    public function idle(): Promise
    {
        return $this->promiseFulfilled(null);
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        $deferred = new class() implements Deferred {
            public $eventLoop;
            private $promise;

            public function getPromise(): Promise
            {
                if (!$this->promise) {
                    throw new \LogicException('Synchronous Deferred must be resolved/rejected before to retrieve its promise.');
                }

                return $this->promise;
            }

            public function resolve($value)
            {
                $this->promise = $this->eventLoop->promiseFulfilled($value);
            }

            public function reject(\Throwable $throwable)
            {
                $this->promise = $this->eventLoop->promiseRejected($throwable);
            }
        };
        $deferred->eventLoop = $this;

        return $deferred;
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }
}
