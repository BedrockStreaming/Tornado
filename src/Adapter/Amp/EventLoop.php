<?php

namespace M6Web\Tornado\Adapter\Amp;

use M6Web\Tornado\Adapter\Common;
use M6Web\Tornado\CancelledException;
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
            $result = \Amp\Promise\wait(
                Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getAmpPromise()
            );
            $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

            return $result;
        } catch (\Error $error) {
            // Modify exceptions sent by Amp itself
            if ($error->getCode() !== 0) {
                throw $error;
            }
            switch ($error->getMessage()) {
                case 'Loop stopped without resolving the promise':
                    throw new \Error('Impossible to resolve the promise, no more task to execute.', 0, $error);
                case 'Loop exceptionally stopped without resolving the promise':
                    throw $error->getPrevious() ?? $error;
                default:
                    throw $error;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        /** @var Promise $currentPromise */
        $currentPromise = null;

        $wrapper = function (\Generator $generator, \Amp\Deferred $deferred) use (&$currentPromise): \Generator {
            try {
                while ($generator->valid()) {
                    $blockingPromise = $generator->current();
                    if (!$blockingPromise instanceof Promise) {
                        throw new \Error('Asynchronous function is yielding a ['.gettype($blockingPromise).'] instead of a Promise.');
                    }
                    $currentPromise = $blockingPromise;
                    $blockingPromise = Internal\PromiseWrapper::toHandledPromise(
                        $blockingPromise,
                        $this->unhandledFailingPromises
                    )->getAmpPromise();

                    // Forwards promise value/exception to underlying generator
                    $blockingPromiseValue = null;
                    $blockingPromiseException = null;
                    try {
                        $blockingPromiseValue = yield $blockingPromise;
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
                $deferred->fail($throwable);

                return;
            }

            $deferred->resolve($generator->getReturn());
        };

        $deferred = new \Amp\Deferred();
        \Amp\Promise\rethrow(new \Amp\Coroutine($wrapper($generator, $deferred)));

        $cancellable = function () use (&$currentPromise) {
            $currentPromise->cancel();
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellable);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $cancellable = function () use (&$promises) {
            foreach ($promises as $promise) {
                $promise->cancel();
            }
        };

        return Internal\PromiseWrapper::createUnhandled(
            \Amp\Promise\all(
                array_map(
                    function (Promise $promise) {
                        $wrappedPromise = Internal\PromiseWrapper::toHandledPromise(
                            $promise,
                            $this->unhandledFailingPromises
                        );

                        return $wrappedPromise->getAmpPromise();
                    },
                    $promises
                )
            ),
            $this->unhandledFailingPromises, $cancellable
        );
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
        $toWrapPromises = [];
        $promisesCancellation = null;
        if (empty($promises)) {
            return $this->promiseFulfilled(null);
        }

        $deferred = new \Amp\Deferred();
        $isFirstPromise = true;

        $wrapPromise = function (\Amp\Promise $promise) use ($deferred, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->fail($throwable);
                }
            }
        };

        $promises = array_map(
            function (Promise $promise) use (&$toWrapPromises) {
                $tempPromise = Internal\PromiseWrapper::toHandledPromise(
                    $promise,
                    $this->unhandledFailingPromises
                );
                $toWrapPromises[] = $tempPromise;

                return $tempPromise->getAmpPromise();
            },
            $promises
        );

        foreach ($promises as $index => $promise) {
            \Amp\Promise\rethrow(new \Amp\Coroutine($wrapPromise($promise)));
        }
        $promisesCancellation = function () use (&$toWrapPromises) {
            foreach ($toWrapPromises as $index => $promise) {
                $promise->cancel();
            }
        };

        $cancellation = function () use (&$deferred, &$promisesCancellation) {
            $deferred->fail(new CancelledException('promise race cancellation'));
            ($promisesCancellation)();
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellation);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createHandled(new \Amp\Success($value), function () {
        });
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        // Manually created promises are considered as handled.
        return Internal\PromiseWrapper::createHandled(new \Amp\Failure($throwable), function () {
        });
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = new \Amp\Deferred();

        $id = \Amp\Loop::defer(function () use ($deferred) {
            $deferred->resolve();
        });

        $cancellation = function () use ($id, $deferred) {
            \Amp\Loop::cancel($id);
            $deferred->fail(new CancelledException('Delay cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellation);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = new \Amp\Deferred();

        $delayId = \Amp\Loop::delay($milliseconds, function () use ($deferred) {
            $deferred->resolve();
        });

        $cancellation = function () use ($delayId, $deferred) {
            \Amp\Loop::cancel($delayId);
            $deferred->fail(new CancelledException('Delay cancelled'));
        };

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellation);
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(callable $canceller = null): Deferred
    {
        return new Internal\Deferred(
            $deferred = new \Amp\Deferred(),
            // Manually created promises are considered as handled.
            Internal\PromiseWrapper::createHandled($deferred->promise(), function () {})
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = new \Amp\Deferred();

        \Amp\Loop::onReadable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Amp\Loop::cancel($watcherId);
                $deferred->resolve($stream);
            }
        );

        $cancellation = function () {};

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellation);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $deferred = new \Amp\Deferred();

        \Amp\Loop::onWritable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Amp\Loop::cancel($watcherId);
                $deferred->resolve($stream);
            }
        );

        $cancellation = function () {};

        return Internal\PromiseWrapper::createUnhandled($deferred->promise(), $this->unhandledFailingPromises, $cancellation);
    }

    public function __construct()
    {
        $this->unhandledFailingPromises = new Common\Internal\FailingPromiseCollection();
    }

    /** @var Common\Internal\FailingPromiseCollection */
    private $unhandledFailingPromises;
}
