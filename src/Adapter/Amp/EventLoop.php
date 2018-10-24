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
            return \Amp\Promise\wait(Internal\PromiseWrapper::downcast($promise)->getAmpPromise());
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
        $wrapper = function (\Generator $generator, callable $fnSuccess, callable $fnFailure): \Generator {
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
                $fnFailure($throwable);
            }

            $fnSuccess($generator->getReturn());
        };

        $deferred = new Internal\Deferred();
        new \Amp\Coroutine($wrapper(
            $generator,
            [$deferred, 'resolve'],
            function (\Throwable $throwable) use ($deferred) {
                if ($deferred->getPromiseWrapper()->hasBeenYielded()) {
                    $deferred->reject($throwable);
                } else {
                    \Amp\Loop::defer(function () use ($throwable) {
                        throw $throwable;
                    });
                }
            }
        ));

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        return new Internal\PromiseWrapper(
            \Amp\Promise\all(Internal\PromiseWrapper::toAmpPromiseArray(...$promises))
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
        $ampPromises = [];
        foreach ($traversable as $key => $value) {
            $ampPromises[] = Internal\PromiseWrapper::downcast(
                $this->async($function($value, $key))
            )->getAmpPromise();
        }

        return new Internal\PromiseWrapper(\Amp\Promise\all($ampPromises));
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

        foreach ($promises as $index => $promise) {
            new \Amp\Coroutine($wrapPromise(
                Internal\PromiseWrapper::downcast($promise)->getAmpPromise()
            ));
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
