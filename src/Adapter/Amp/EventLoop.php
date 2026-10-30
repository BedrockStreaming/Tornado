<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Amp;

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
            $result = \Amp\Future\await([Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getAmpFuture()]);
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

        $wrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);
        $this->resumeOn($pending, $generator, $wrapper);

        return $wrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $globalWrapper = null;
        $firstError = null;
        $results = [];
        $remaining = \count($promises);

        foreach ($promises as $i => $promise) {
            $wrapper = Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises);

            if ($firstError !== null) {
                continue;
            }

            if ($wrapper->isSettled()) {
                $error = $wrapper->getError();

                if ($error === null) {
                    $results[$i] = $wrapper->getValue();
                    $remaining--;

                    continue;
                }

                if ($globalWrapper === null) {
                    $firstError = $error;

                    continue;
                }
            }

            $results[$i] = null;
            $globalWrapper ??= Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

            $wrapper->onSettle(static function (?\Throwable $error, mixed $value) use ($i, &$results, &$remaining, $globalWrapper): void {
                if ($globalWrapper->isSettled()) {
                    return;
                }

                if ($error !== null) {
                    $globalWrapper->settle(error: $error);

                    return;
                }

                $results[$i] = $value;

                if (--$remaining === 0) {
                    $globalWrapper->settle(value: $results);
                }
            });
        }

        if ($firstError !== null) {
            return $this->rejectedUnhandled($firstError);
        }

        if ($globalWrapper === null) {
            return Internal\PromiseWrapper::createFulfilled($results);
        }

        return $globalWrapper;
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

        $globalWrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

        foreach ($promises as $promise) {
            Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)
                ->onSettle(static function (?\Throwable $error, mixed $value) use ($globalWrapper): void {
                    if (!$globalWrapper->isSettled()) {
                        $globalWrapper->settle($error, $value);
                    }
                });
        }

        return $globalWrapper;
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
        $promise = Internal\PromiseWrapper::createHandled();
        $promise->settle(error: $throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $wrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

        \Revolt\EventLoop::defer(static function () use ($wrapper): void {
            $wrapper->settle();
        });

        return $wrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $wrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

        \Revolt\EventLoop::delay($milliseconds / 1000, static function () use ($wrapper): void {
            $wrapper->settle();
        });

        return $wrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new Internal\Deferred(Internal\PromiseWrapper::createHandled());
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $wrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

        \Revolt\EventLoop::onReadable(
            $stream,
            static function ($watcherId, $stream) use ($wrapper): void {
                \Revolt\EventLoop::cancel($watcherId);
                $wrapper->settle(value: $stream);
            }
        );

        return $wrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $wrapper = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);

        \Revolt\EventLoop::onWritable(
            $stream,
            static function ($watcherId, $stream) use ($wrapper): void {
                \Revolt\EventLoop::cancel($watcherId);
                $wrapper->settle(value: $stream);
            }
        );

        return $wrapper;
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
        $promise = Internal\PromiseWrapper::createUnhandled($this->unhandledFailingPromises);
        $promise->settle(error: $throwable);

        return $promise;
    }

    private function resumeOn(Internal\PromiseWrapper $pending, \Generator $generator, Internal\PromiseWrapper $wrapper): void
    {
        $pending->onSettle(function (?\Throwable $error, mixed $value) use ($generator, $wrapper): void {
            try {
                if ($error !== null) {
                    $generator->throw($error);
                } else {
                    $generator->send($value);
                }

                $next = $this->advance($generator);
            } catch (\Throwable $throwable) {
                $wrapper->settle(error: $throwable);

                return;
            }

            if ($next !== null) {
                $this->resumeOn($next, $generator, $wrapper);

                return;
            }

            $wrapper->settle(value: $generator->getReturn());
        });
    }
}
