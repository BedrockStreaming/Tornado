<?php

namespace M6Web\Tornado\Adapter\Amp;

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
            return \Amp\Promise\wait(Internal\PromiseWrapper::toWatchedAmpPromise($promise));
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
        $wrapper = function (\Generator $generator, Internal\Deferred $deferred): \Generator {
            try {
                while ($generator->valid()) {
                    $blockingPromise = Internal\PromiseWrapper::fromGenerator($generator)->getAmpPromise();

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
                $deferred->reject($throwable);
            }

            $deferred->resolve($generator->getReturn());
        };

        $deferred = Internal\Deferred::forAsync();
        new \Amp\Coroutine($wrapper($generator, $deferred));

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        return new Internal\PromiseWrapper(
            \Amp\Promise\all(Internal\PromiseWrapper::toWatchedAmpPromiseArray(...$promises))
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

        $promises = Internal\PromiseWrapper::toWatchedAmpPromiseArray(...$promises);

        foreach ($promises as $index => $promise) {
            new \Amp\Coroutine($wrapPromise($promise));
        }

        return new Internal\PromiseWrapper($deferred->promise());
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return new Internal\PromiseWrapper(
            new \Amp\Success($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return new Internal\PromiseWrapper(
            new \Amp\Failure($throwable)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = new \Amp\Deferred();

        \Amp\Loop::defer(function () use ($deferred) {
            $deferred->resolve();
        });

        return new Internal\PromiseWrapper($deferred->promise());
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = new \Amp\Deferred();

        \Amp\Loop::delay($milliseconds, function () use ($deferred) {
            $deferred->resolve();
        });

        return new Internal\PromiseWrapper($deferred->promise());
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
        $deferred = new \Amp\Deferred();

        \Amp\Loop::onReadable(
            $stream,
            function ($watcherId, $stream) use ($deferred) {
                \Amp\Loop::cancel($watcherId);
                $deferred->resolve($stream);
            }
        );

        return new Internal\PromiseWrapper($deferred->promise());
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

        return new Internal\PromiseWrapper($deferred->promise());
    }
}
