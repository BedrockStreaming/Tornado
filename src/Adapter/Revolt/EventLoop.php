<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Revolt;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Adapter\Tornado\Internal\PendingPromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Revolt\EventLoop as Loop;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private FailingPromiseCollection $unhandledFailingPromises;

    public function __construct()
    {
        $this->unhandledFailingPromises = new FailingPromiseCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $value = null;
        $throwable = null;
        $suspension = Loop::getSuspension();

        // Resume is queued because the promise may already be settled, and resuming before suspending is an error.
        PendingPromise::toHandledPromise($promise)->addCallbacks(
            function ($result) use (&$value, $suspension): void {
                $value = $result;
                Loop::queue($suspension->resume(...));
            },
            function (\Throwable $error) use (&$throwable, $suspension): void {
                $throwable = $error;
                Loop::queue($suspension->resume(...));
            }
        );

        try {
            $suspension->suspend();
        } catch (\Error $error) {
            if (!str_starts_with($error->getMessage(), 'Event loop terminated without resuming the current suspension')) {
                throw $error;
            }
            throw new \Error('Impossible to resolve the promise, no more task to execute.', 0, $error);
        }

        $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

        if ($throwable !== null) {
            throw $throwable;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $promise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        $this->run($generator, $promise);

        return $promise;
    }

    /**
     * Runs the generator synchronously as long as it yields settled promises,
     * then schedules its resumption once the yielded promise is settled.
     *
     * @param \Generator<int, Promise, mixed, mixed> $generator
     * @param PendingPromise<mixed>                  $promise
     */
    private function run(\Generator $generator, PendingPromise $promise): void
    {
        try {
            while ($generator->valid()) {
                $blockingPromise = $generator->current();
                if (!$blockingPromise instanceof Promise) {
                    throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
                }
                $blockingPromise = PendingPromise::toHandledPromise($blockingPromise);

                if (!$blockingPromise->isSettled()) {
                    // Queued so that the resolver's call stack is not re-entered.
                    $blockingPromise->onSettle(
                        fn (?\Throwable $throwable, $value) => Loop::queue($this->resume(...), $generator, $promise, $value, $throwable),
                    );

                    return;
                }

                if (($throwable = $blockingPromise->getThrowable()) !== null) {
                    $generator->throw($throwable);
                } else {
                    $generator->send($blockingPromise->getValue());
                }
            }
        } catch (\Throwable $throwable) {
            $promise->reject($throwable);

            return;
        }

        $promise->resolve($generator->getReturn());
    }

    /**
     * @param \Generator<int, Promise, mixed, mixed> $generator
     * @param PendingPromise<mixed>                  $promise
     */
    private function resume(\Generator $generator, PendingPromise $promise, mixed $value, ?\Throwable $throwable): void
    {
        try {
            if ($throwable !== null) {
                $generator->throw($throwable);
            } else {
                $generator->send($value);
            }
        } catch (\Throwable $throwable) {
            $promise->reject($throwable);

            return;
        }

        $this->run($generator, $promise);
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

        $globalPromise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        $allResults = array_fill_keys(array_keys($promises), null);

        foreach ($promises as $index => $promise) {
            $promise = PendingPromise::toHandledPromise($promise);
            if ($promise->isSettled() && $promise->getThrowable() === null) {
                $allResults[$index] = $promise->getValue();
                if (--$nbPromises === 0) {
                    $globalPromise->resolve($allResults);
                }
                continue;
            }
            $promise->onSettle(
                function (?\Throwable $throwable, $value) use ($index, $globalPromise, &$nbPromises, &$allResults): void {
                    if ($throwable === null) {
                        $allResults[$index] = $value;
                        if (--$nbPromises === 0) {
                            $globalPromise->resolve($allResults);
                        }
                    } elseif ($nbPromises > 0) {
                        $nbPromises = -1;
                        $globalPromise->reject($throwable);
                    }
                }
            );
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

        $globalPromise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        $isFirstPromise = true;

        foreach ($promises as $promise) {
            PendingPromise::toHandledPromise($promise)->addCallbacks(
                function ($value) use ($globalPromise, &$isFirstPromise): void {
                    if ($isFirstPromise) {
                        $isFirstPromise = false;
                        $globalPromise->resolve($value);
                    }
                },
                function (\Throwable $throwable) use ($globalPromise, &$isFirstPromise): void {
                    if ($isFirstPromise) {
                        $isFirstPromise = false;
                        $globalPromise->reject($throwable);
                    }
                }
            );
        }

        return $globalPromise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        $promise = PendingPromise::createHandled();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        // Manually created promises are considered as handled.
        $promise = PendingPromise::createHandled();
        $promise->reject($throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $promise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        Loop::defer(fn () => $promise->resolve(null));

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $promise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        Loop::delay($milliseconds / 1000, fn () => $promise->resolve(null));

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        // Manually created promises are considered as handled.
        return PendingPromise::createHandled();
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $promise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        Loop::onReadable($stream, function (string $callbackId, $stream) use ($promise): void {
            Loop::cancel($callbackId);
            $promise->resolve($stream);
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $promise = PendingPromise::createUnhandled($this->unhandledFailingPromises);
        Loop::onWritable($stream, function (string $callbackId, $stream) use ($promise): void {
            Loop::cancel($callbackId);
            $promise->resolve($stream);
        });

        return $promise;
    }
}
