<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Amp;

use Amp\DeferredFuture;
use Amp\Future;
use M6Web\Tornado\Adapter\Common;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private Common\Internal\FailingPromiseCollection $unhandledFailingPromises;

    public function __construct()
    {
        $this->unhandledFailingPromises = new Common\Internal\FailingPromiseCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        try {
            $result = \Amp\Future\await([Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->ampFuture]);
            $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

            return $result[0] ?? null;
        } catch (\Error $error) {
            // Modify exceptions sent by Amp itself
            if ($error->getCode() !== 0) {
                throw $error;
            }

            if (str_starts_with($error->getMessage(), 'Event loop terminated without resuming the current suspension')) {
                throw new \Error('Impossible to resolve the promise, no more task to execute.', 0, $error);
            }

            throw match ($error->getMessage()) {
                'Loop exceptionally stopped without resolving the promise' => $error->getPrevious() ?? $error,
                default => $error,
            };
        }
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        try {
            $pending = $this->advance($generator);
        } catch (\Throwable $throwable) {
            return $this->rejectedUnhandled($throwable);
        }

        if ($pending === null) {
            return Internal\PromiseWrapper::createFulfilled($generator->getReturn());
        }

        $deferred = new DeferredFuture();
        $wrapper = Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
        $this->resumeOn($pending, $generator, $deferred, $wrapper);

        return $wrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $wrappers = array_map(
            fn (Promise $promise): Internal\PromiseWrapper => Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises),
            $promises
        );

        $values = [];

        foreach ($wrappers as $index => $promise) {
            if (!$promise->isSettled()) {
                $values = null;

                break;
            }

            if ($promise->getError() !== null) {
                return $this->rejectedUnhandled($promise->getError());
            }

            $values[$index] = $promise->getValue();
        }

        if ($values !== null) {
            return Internal\PromiseWrapper::createFulfilled($values);
        }

        $deferred = new DeferredFuture();
        $wrapper = Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
        $results = \array_fill_keys(\array_keys($wrappers), null);
        $remaining = \count($wrappers);

        foreach ($wrappers as $index => $promise) {
            $promise->ampFuture
                ->map(static function (mixed $value) use ($index, &$results, &$remaining, $deferred, $wrapper): void {
                    $results[$index] = $value;

                    if (--$remaining === 0 && !$deferred->isComplete()) {
                        $wrapper->settle(null, $results);
                        $deferred->complete($results);
                    }
                })
                ->catch(static function (\Throwable $error) use ($deferred, $wrapper): void {
                    if (!$deferred->isComplete()) {
                        $wrapper->settle($error, null);
                        $deferred->error($error);
                    }
                })
                ->ignore();
        }

        return $wrapper;
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

        $deferred = new DeferredFuture();
        $isFirstPromise = true;

        $wrapPromise = function (Future $future) use ($deferred, &$isFirstPromise) {
            try {
                $result = $future->await();
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->complete($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->error($throwable);
                }
            }
        };

        $futures = array_map(
            fn (Promise $promise) => Internal\PromiseWrapper::toHandledPromise(
                $promise,
                $this->unhandledFailingPromises
            )->ampFuture,
            $promises
        );

        foreach ($futures as $future) {
            \Amp\async(fn () => $wrapPromise($future));
        }

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createFulfilled($value);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        $promise = Internal\PromiseWrapper::createHandled(Future::error($throwable));
        $promise->settle($throwable, null);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = new DeferredFuture();

        \Revolt\EventLoop::defer(function () use ($deferred): void {
            $deferred->complete();
        });

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = new DeferredFuture();

        \Revolt\EventLoop::delay($milliseconds / 1000, function () use ($deferred): void {
            $deferred->complete();
        });

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new Internal\Deferred(
            $deferred = new DeferredFuture(),
            Internal\PromiseWrapper::createHandled($deferred->getFuture())
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = new DeferredFuture();

        \Revolt\EventLoop::onReadable(
            $stream,
            function ($watcherId, $stream) use ($deferred): void {
                \Revolt\EventLoop::cancel($watcherId);
                $deferred->complete($stream);
            }
        );

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $deferred = new DeferredFuture();

        \Revolt\EventLoop::onWritable(
            $stream,
            function ($watcherId, $stream) use ($deferred): void {
                \Revolt\EventLoop::cancel($watcherId);
                $deferred->complete($stream);
            }
        );

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    private function advance(\Generator $generator): ?Internal\PromiseWrapper
    {
        while ($generator->valid()) {
            $blockingPromise = $generator->current();

            if (!$blockingPromise instanceof Promise) {
                throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
            }

            $blockingPromise = Internal\PromiseWrapper::toHandledPromise($blockingPromise, $this->unhandledFailingPromises);

            if (!$blockingPromise->isSettled()) {
                return $blockingPromise;
            }

            if ($blockingPromise->getError() !== null) {
                $generator->throw($blockingPromise->getError());
            } else {
                $generator->send($blockingPromise->getValue());
            }
        }

        return null;
    }

    private function rejectedUnhandled(\Throwable $throwable): Internal\PromiseWrapper
    {
        $promise = Internal\PromiseWrapper::createUnhandled(Future::error($throwable), $this->unhandledFailingPromises);
        $promise->settle($throwable, null);

        return $promise;
    }

    private function resumeOn(Internal\PromiseWrapper $pending, \Generator $generator, DeferredFuture $deferred, Internal\PromiseWrapper $wrapper): void
    {
        $step = function (?\Throwable $error, mixed $value) use ($generator, $deferred, $wrapper): void {
            try {
                if ($error !== null) {
                    $generator->throw($error);
                } else {
                    $generator->send($value);
                }

                $next = $this->advance($generator);
            } catch (\Throwable $throwable) {
                $wrapper->settle($throwable, null);
                $deferred->error($throwable);

                return;
            }

            if ($next !== null) {
                $this->resumeOn($next, $generator, $deferred, $wrapper);

                return;
            }

            $return = $generator->getReturn();
            $wrapper->settle(null, $return);
            $deferred->complete($return);
        };

        $pending->ampFuture
            ->map(static fn (mixed $value) => $step(null, $value))
            ->catch(static fn (\Throwable $error) => $step($error, null))
            ->ignore();
    }
}
