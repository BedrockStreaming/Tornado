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
        return \Amp\Promise\wait(self::toAmpPromise($promise));
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $wrapper = function (\Generator $generator): \Generator {
            while ($generator->valid()) {
                $blockingPromise = self::toAmpPromise($generator->current());

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

            return $generator->getReturn();
        };

        return self::fromAmpPromise(
            new \Amp\Coroutine($wrapper($generator))
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $ampPromises = array_map([self::class, 'toAmpPromise'], $promises);

        return self::fromAmpPromise(\Amp\Promise\all($ampPromises));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return self::fromAmpPromise(
            new \Amp\Success($value)
        );
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return self::fromAmpPromise(
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

        return self::fromAmpPromise($deferred->promise());
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        $deferred = new class() implements Deferred {
            public $ampDeferred;
            public $promise;

            public function getPromise(): Promise
            {
                return $this->promise;
            }

            public function resolve($value)
            {
                $this->ampDeferred->resolve($value);
            }

            public function reject(\Throwable $throwable)
            {
                $this->ampDeferred->fail($throwable);
            }
        };
        $deferred->ampDeferred = new \Amp\Deferred();
        $deferred->promise = self::fromAmpPromise($deferred->ampDeferred->promise());

        return $deferred;
    }

    private static function fromAmpPromise(\Amp\Promise $ampPromise): Promise
    {
        $promise = new class() implements Promise {
            public $ampPromise;
        };
        $promise->ampPromise = $ampPromise;

        return $promise;
    }

    private static function toAmpPromise(Promise $promise): \Amp\Promise
    {
        if (!property_exists($promise, 'ampPromise')) {
            throw new \LogicException('');
        }

        return $promise->ampPromise;
    }
}
