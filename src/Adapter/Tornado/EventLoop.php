<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        while ($promise->isPending() && $this->tasks) {
            // Copy tasks list to safely allow tasks addition by tasks themselves
            $allTasks = $this->tasks;
            $this->tasks = [];
            foreach ($allTasks as $task) {
                try {
                    if (!$task->generator->valid()) {
                        $task->promise->resolve($task->generator->getReturn());
                        // This task is finished
                        continue;
                    }

                    $blockingPromise = $task->generator->current();
                    $blockingPromise->sendValueToGenerator($task->generator);
                    $this->tasks[] = $task;
                } catch (\Throwable $exception) {
                    $task->promise->reject($exception);
                }
            }
        }

        return $promise->getValue();
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $this->tasks[] = ($task = $this->createTask($generator));

        return $task->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $globalPromise = $this->promisePending();
        $nbPromises = count($promises);
        $allResults = [];

        // To be sure that the last resolved promise resolves the global promise immediately,
        //
        $waitOnePromise = function (int $index, Promise $promise) use ($globalPromise, $nbPromises, &$allResults): \Generator {
            try {
                $allResults[$index] = yield $promise;
            } catch (\Throwable $throwable) {
                // Prevent to reject the globalPromise twice
                if ($globalPromise->isPending()) {
                    $globalPromise->reject($throwable);

                    return;
                }
            }
            // Last resolved promise resolved globalPromise
            if ($nbPromises === count($allResults)) {
                $globalPromise->resolve($allResults);
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($waitOnePromise($index, $promise));
        }

        return $globalPromise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return $this->promisePending()->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return $this->promisePending()->reject($throwable);
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        // Add an async function that resolve immediately
        return $this->async((function (): \Generator {
            return;
            yield;
        })());
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        // Encapsulate internal pending promise in (stricter) Deferred interface
        return new class($this->promisePending()) implements Deferred {
            private $promise;

            public function __construct(Promise $promise)
            {
                $this->promise = $promise;
            }

            public function getPromise(): Promise
            {
                return $this->promise;
            }

            public function resolve($value)
            {
                $this->promise->resolve($value);
            }

            public function reject(\Throwable $throwable)
            {
                $this->promise->reject($throwable);
            }
        };
    }

    // Tasks are composed of a generator to execute, and the promise of its result.
    private $tasks = [];

    private function createTask(\Generator $generator)
    {
        $task = new class() {
            public $generator;
            public $promise;
        };
        $task->generator = $generator;
        $task->promise = $this->promisePending();

        return $task;
    }

    private function promisePending(): Promise
    {
        return new class() implements Promise {
            private const PENDING = 1;
            private const FULFILLED = 2;
            private const REJECTED = 3;

            private $value;
            private $state = self::PENDING;

            public function isPending(): bool
            {
                return $this->state === self::PENDING;
            }

            public function resolve($value): Promise
            {
                if ($this->state !== self::PENDING) {
                    throw new \LogicException('Cannot resolve a non pending promise.');
                }
                $this->value = $value;
                $this->state = self::FULFILLED;

                return $this;
            }

            public function reject(\Throwable $throwable): Promise
            {
                if ($this->state !== self::PENDING) {
                    throw new \LogicException('Cannot reject a non pending promise.');
                }
                $this->value = $throwable;
                $this->state = self::REJECTED;

                return $this;
            }

            /**
             * Sends promise value to the generator according to its internal state.
             */
            public function sendValueToGenerator(\Generator $generator): void
            {
                switch ($this->state) {
                    case self::FULFILLED:
                        $generator->send($this->value);
                        break;
                    case self::REJECTED:
                        $generator->throw($this->value);
                        break;
                    default:
                }
            }

            /**
             * Throws an exception if the promise is not fulfilled.
             */
            public function getValue()
            {
                switch ($this->state) {
                    case self::FULFILLED:
                        return $this->value;
                        break;
                    case self::REJECTED:
                        throw $this->value;
                        break;
                    default:
                        throw new \Exception('Cannot resolve promise.');
                }
            }
        };
    }
}
