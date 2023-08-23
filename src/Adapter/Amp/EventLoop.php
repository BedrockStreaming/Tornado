<?php

namespace M6Web\Tornado\Adapter\Amp;

use Amp\Future;
use M6Web\Tornado\Adapter\Common;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
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
        $wrapper = function (\Generator $generator, \Amp\DeferredFuture $deferred) {
            try {
                while ($generator->valid()) {
                    $blockingPromise = $generator->current();
                    if (!$blockingPromise instanceof Promise) {
                        throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
                    }
                    $blockingPromise = Internal\PromiseWrapper::toHandledPromise(
                        $blockingPromise,
                        $this->unhandledFailingPromises
                    )->getAmpFuture();

                    // Forwards promise value/exception to underlying generator
                    $blockingPromiseValue = null;
                    $blockingPromiseException = null;
                    try {
                        $blockingPromiseValue = $blockingPromise->await();
                    } catch (\Throwable $throwable) {
                        $blockingPromiseException = $throwable;
                    }
                    if ($blockingPromiseException) {
                        $generator->throw($blockingPromiseException);
                    } else {
                        $generator->send($blockingPromiseValue);
                    }
                }
            } catch (\Throwable $throwable) {
                $deferred->error($throwable);

                return;
            }

            $deferred->complete($generator->getReturn());
        };

        $deferred = new \Amp\DeferredFuture();
        \Amp\async(fn() => $wrapper($generator, $deferred));

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $generator = function() use ($promises): \Generator {
            $result = [];

            foreach ($promises as $promise) {
                $result[] = yield Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises);
            }

            return $result;
        };

        return $this->async($generator());
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

        $deferred = new \Amp\DeferredFuture();
        $isFirstPromise = true;

        $wrapPromise = function (\Amp\Future $future) use ($deferred, &$isFirstPromise) {
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
            function (Promise $promise) {
                return Internal\PromiseWrapper::toHandledPromise(
                    $promise,
                    $this->unhandledFailingPromises
                )->getAmpFuture();
            },
            $promises
        );

        foreach ($futures as $index => $future) {
            \Amp\async(fn() => $wrapPromise($future));
        }

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createHandled(Future::complete($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return Internal\PromiseWrapper::createHandled(Future::error($throwable));
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = new \Amp\DeferredFuture();

        \Revolt\EventLoop::defer(function () use ($deferred) {
            $deferred->complete();
        });

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = new \Amp\DeferredFuture();

        \Revolt\EventLoop::delay($milliseconds/1000, function () use ($deferred) {
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
            $deferred = new \Amp\DeferredFuture(),
            Internal\PromiseWrapper::createHandled($deferred->getFuture())
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = new \Amp\DeferredFuture();

        \Revolt\EventLoop::onReadable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
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
        $deferred = new \Amp\DeferredFuture();

        \Revolt\EventLoop::onWritable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Revolt\EventLoop::cancel($watcherId);
                $deferred->complete($stream);
            }
        );

        return Internal\PromiseWrapper::createUnhandled($deferred->getFuture(), $this->unhandledFailingPromises);
    }

    public function __construct()
    {
        $this->unhandledFailingPromises = new Common\Internal\FailingPromiseCollection();
    }

    private Common\Internal\FailingPromiseCollection $unhandledFailingPromises;
}
