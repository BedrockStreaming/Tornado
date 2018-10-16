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
            return \Amp\Promise\wait(self::toAmpPromise($promise));
        } catch (\Error $error) {
            // Modify exceptions sent by Amp itself
            if ($error->getCode() !== 0) {
                throw $error;
            }
            switch ($error->getMessage()) {
                case 'Loop stopped without resolving the promise':
                    throw new \Error('Impossible to resolve the promise, no more task to execute.', 0, $error);
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
            new \Amp\Coroutine($wrapPromise(self::toAmpPromise($promise)));
        }

        return self::fromAmpPromise($deferred->promise());
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
    public function delay(int $milliseconds): Promise
    {
        $deferred = new \Amp\Deferred();

        \Amp\Loop::delay($milliseconds, function () use ($deferred) {
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

        return self::fromAmpPromise($deferred->promise());
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

        return self::fromAmpPromise($deferred->promise());
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
