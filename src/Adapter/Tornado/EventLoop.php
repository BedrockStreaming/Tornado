<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /**
     * @var Internal\StreamEventLoop
     */
    private $streamLoop;

    /**
     * @var Internal\Task[]
     */
    private $tasks = [];

    public function __construct()
    {
        $this->streamLoop = new Internal\StreamEventLoop();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $promiseIsPending = true;
        $finalAction = function () {throw new \Error('Impossible to resolve the promise, no more task to execute..'); };
        Internal\PendingPromise::downcast($promise)->addCallbacks(
            function ($value) use (&$finalAction, &$promiseIsPending) {
                $promiseIsPending = false;
                $finalAction = function () use ($value) {return $value; };
            },
            function (\Throwable $throwable) use (&$finalAction, &$promiseIsPending) {
                $promiseIsPending = false;
                $finalAction = function () use ($throwable) {throw $throwable; };
            }
        );

        // Workaround to solve PhpStan false positive
        $somethingToDo = function (): bool {
            return count($this->tasks) !== 0;
        };

        $fnThrowIfNotNull = function (?\Throwable $throwable) {
            if ($throwable !== null) {
                throw $throwable;
            }
        };

        $globalException = null;
        // Returns a callback to propagate a value to a generator via $function
        $fnSafeGeneratorCallback = function (Internal\Task $task, string $function) use (&$globalException) {
            return function ($value) use ($task, $function, &$globalException) {
                try {
                    $task->getGenerator()->$function($value);
                    $this->tasks[] = $task;
                } catch (\Throwable $exception) {
                    if ($task->getPromise()->hasBeenYielded()) {
                        $task->getPromise()->reject($exception);
                    } else {
                        $globalException = $exception;
                    }
                }
            };
        };

        do {
            // Copy tasks list to safely allow tasks addition by tasks themselves
            $allTasks = $this->tasks;
            $this->tasks = [];
            foreach ($allTasks as $task) {
                try {
                    if (!$task->getGenerator()->valid()) {
                        $task->getPromise()->resolve($task->getGenerator()->getReturn());
                        // This task is finished
                        continue;
                    }

                    $blockingPromise = Internal\PendingPromise::fromGenerator($task->getGenerator());
                    $blockingPromise->addCallbacks(
                        $fnSafeGeneratorCallback($task, 'send'),
                        $fnSafeGeneratorCallback($task, 'throw')
                    );
                } catch (\Throwable $exception) {
                    if ($task->getPromise()->hasBeenYielded()) {
                        $task->getPromise()->reject($exception);
                    } else {
                        throw $exception;
                    }
                }

                $fnThrowIfNotNull($globalException);
            }
        } while ($promiseIsPending && $somethingToDo());

        return $finalAction();
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $this->tasks[] = ($task = new Internal\Task($generator));

        return $task->getPromise();
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

        $globalPromise = new Internal\PendingPromise();
        $allResults = array_fill(0, $nbPromises, false);

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = function (int $index, Promise $promise) use ($globalPromise, &$nbPromises, &$allResults): \Generator {
            try {
                $allResults[$index] = yield $promise;
            } catch (\Throwable $throwable) {
                // Prevent to reject the globalPromise twice
                if ($nbPromises > 0) {
                    $nbPromises = -1;
                    $globalPromise->reject($throwable);

                    return;
                }
            }
            // Last resolved promise resolved globalPromise
            if (--$nbPromises === 0) {
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
    public function promiseForeach($traversable, callable $function): Promise
    {
        $promises = [];
        foreach ($traversable as $key => $value) {
            $promises[] = $this->async($function($value, $key));
        }

        return $this->promiseAll(...$promises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        if (empty($promises)) {
            return $this->promiseFulfilled(null);
        }

        $globalPromise = new Internal\PendingPromise();
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
        return (new Internal\PendingPromise())->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return (new Internal\PendingPromise())->reject($throwable);
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        // Add an asynchronous function that resolve immediately
        return $this->async((function (): \Generator {
            return;
            yield;
        })());
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $endTime = microtime(true) + $milliseconds / 1000 /* milliseconds in 1 second */;

        return $this->async((function () use ($endTime): \Generator {
            while (microtime(true) < $endTime) {
                yield $this->idle();
            }
        })());
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new Internal\Deferred();
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->streamLoop->readable($this, $stream);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->streamLoop->writable($this, $stream);
    }
}
