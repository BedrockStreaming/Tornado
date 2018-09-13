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
        do {
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
        } while ($promise->isPending() && $this->tasks);

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
        $nbPromises = count($promises);
        if ($nbPromises === 0) {
            return $this->promiseFulfilled([]);
        }

        $globalPromise = $this->promisePending();
        $allResults = [];

        // To ensure that the last resolved promise resolves the global promise immediately
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
    public function promiseRace(Promise ...$promises): Promise
    {
        if (empty($promises)) {
            return $this->promiseFulfilled(null);
        }

        $globalPromise = $this->promisePending();
        $isFirstPromise = true;

        $wrapPromise = function (Promise $promise) use ($globalPromise, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->reject($throwable);
                }
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($wrapPromise($promise));
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

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $task = $this->readStreamTasks[(int) $stream] ?? $this->createStreamTask($stream, $this->readStreamTasks);

        return $task->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $task = $this->writeStreamTasks[(int) $stream] ?? $this->createStreamTask($stream, $this->writeStreamTasks);

        return $task->promise;
    }

    // Tasks are composed of a generator to execute, and the promise of its result.
    private $tasks = [];
    private $readStreamTasks = [];
    private $writeStreamTasks = [];

    private function streamsLoop(): \Generator
    {
        $except = null;
        while ($this->readStreamTasks || $this->writeStreamTasks) {
            yield $this->idle();

            $read = array_column($this->readStreamTasks, 'stream');
            $write = array_column($this->writeStreamTasks, 'stream');
            stream_select($read, $write, $except, 0);

            foreach ($read as $stream) {
                $streamId = (int) $stream;
                $readStream = $this->readStreamTasks[$streamId];
                unset($this->readStreamTasks[$streamId]);
                $readStream->promise->resolve($stream);
            }

            foreach ($write as $stream) {
                $streamId = (int) $stream;
                $writeStream = $this->writeStreamTasks[$streamId];
                unset($this->writeStreamTasks[$streamId]);
                $writeStream->promise->resolve($stream);
            }
        }
    }

    private function createStreamTask($stream, array &$streamTasks)
    {
        $task = new class() {
            public $stream;
            public $promise;
        };
        $task->stream = $stream;
        $task->promise = $this->promisePending();
        $streamTasks[(int) $stream] = $task;

        if (count($this->readStreamTasks) + count($this->writeStreamTasks) === 1) {
            $this->async($this->streamsLoop());
        }

        return $task;
    }

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
