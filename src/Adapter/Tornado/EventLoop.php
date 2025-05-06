<?php

namespace M6Web\Tornado\Adapter\Tornado;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /** @var Internal\StreamEventLoop */
    private $streamLoop;

    /** @var Internal\Task<mixed>[] */
    private $tasks = [];

    /** @var FailingPromiseCollection */
    private $unhandledFailingPromises;

    public function __construct()
    {
        $this->streamLoop = new Internal\StreamEventLoop();
        $this->unhandledFailingPromises = new FailingPromiseCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $promiseIsPending = true;
        $finalAction = function (): void {throw new \Error('Impossible to resolve the promise, no more task to execute..'); };
        Internal\PendingPromise::toHandledPromise($promise)->addCallbacks(
            function ($value) use (&$finalAction, &$promiseIsPending): void {
                $promiseIsPending = false;
                $finalAction = fn() => $value;
            },
            function (\Throwable $throwable) use (&$finalAction, &$promiseIsPending): void {
                $promiseIsPending = false;
                $finalAction = function () use ($throwable): void {throw $throwable; };
            }
        );

        // Workaround to solve PhpStan false positive
        $somethingToDo = fn(): bool => count($this->tasks) !== 0;

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

                    $blockingPromise = $task->getGenerator()->current();
                    if (!$blockingPromise instanceof Internal\PendingPromise) {
                        throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
                    }
                    $blockingPromise = Internal\PendingPromise::toHandledPromise($blockingPromise);
                    $blockingPromise->addCallbacks(
                        function ($value) use ($task): void {
                            try {
                                $task->getGenerator()->send($value);
                                $this->tasks[] = $task;
                            } catch (\Throwable $exception) {
                                $task->getPromise()->reject($exception);
                            }
                        },
                        function (\Throwable $throwable) use ($task): void {
                            try {
                                $task->getGenerator()->throw($throwable);
                                $this->tasks[] = $task;
                            } catch (\Throwable $exception) {
                                $task->getPromise()->reject($exception);
                            }
                        }
                    );
                } catch (\Throwable $exception) {
                    $task->getPromise()->reject($exception);
                }
            }
        } while ($promiseIsPending && $somethingToDo());

        $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

        return $finalAction();
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $this->tasks[] = ($task = new Internal\Task(
            $generator,
            Internal\PendingPromise::createUnhandled($this->unhandledFailingPromises))
        );

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

        $globalPromise = Internal\PendingPromise::createUnhandled($this->unhandledFailingPromises);
        $allResults = array_fill_keys(array_keys($promises), false);

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = function (int|string $index, Promise $promise) use ($globalPromise, &$nbPromises, &$allResults): \Generator {
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

        $globalPromise = Internal\PendingPromise::createUnhandled($this->unhandledFailingPromises);
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
        $promise = Internal\PendingPromise::createHandled();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        // Manually created promises are considered as handled.
        $promise = Internal\PendingPromise::createHandled();
        $promise->reject($throwable);

        return $promise;
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

            return null;
        })());
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        // Manually created promises are considered as handled.
        return Internal\PendingPromise::createHandled();
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
