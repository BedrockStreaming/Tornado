<?php

namespace M6Web\Tornado\Adapter\ReactPhp;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private $reactEventLoop;

    public function __construct(\React\EventLoop\LoopInterface $reactEventLoop)
    {
        $this->reactEventLoop = $reactEventLoop;
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $value = null;
        $isRejected = false;
        self::toReactPromise($promise)->then(
            function ($result) use (&$value) {
                $value = $result;
                $this->reactEventLoop->stop();
            },
            function ($result) use (&$value, &$isRejected) {
                $value = $result;
                $isRejected = true;
                $this->reactEventLoop->stop();
            }
        );
        $this->reactEventLoop->run();

        if ($isRejected) {
            /* @var $value \Exception */
            throw $value;
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $wrapper = new class() {
            public $generator;
            public $deferred;

            public function onFulfilled($result)
            {
                try {
                    $this->generator->send($result);
                    $this->wrap();
                } catch (\Throwable $throwable) {
                    $this->deferred->reject($throwable);
                }
            }

            public function onRejected($reason)
            {
                try {
                    $this->generator->throw($reason);
                    $this->wrap();
                } catch (\Throwable $throwable) {
                    $this->deferred->reject($throwable);
                }
            }

            public function wrap()
            {
                try {
                    if (!$this->generator->valid()) {
                        return $this->deferred->resolve($this->generator->getReturn());
                    }
                    $blockingPromise = $this->generator->current();
                    self::toReactPromise($blockingPromise)->then([$this, 'onFulfilled'], [$this, 'onRejected']);
                } catch (\Throwable $throwable) {
                    $this->deferred->reject($throwable);
                }
            }

            private static function toReactPromise(Promise $promise): \React\Promise\PromiseInterface
            {
                return $promise->reactPromise;
            }
        };

        $wrapper->generator = $generator;
        $wrapper->deferred = $this->deferred();
        $wrapper->wrap();

        return $wrapper->deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $reactPromises = array_map([self::class, 'toReactPromise'], $promises);

        return self::fromReactPromise(\React\Promise\all($reactPromises));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        $reactPromises = array_map([self::class, 'toReactPromise'], $promises);

        return self::fromReactPromise(\React\Promise\race($reactPromises));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return self::fromReactPromise(new \React\Promise\FulfilledPromise($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return self::fromReactPromise(new \React\Promise\RejectedPromise($throwable));
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->futureTick(function () use ($deferred) {
            $deferred->resolve(null);
        });

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addTimer(
            $milliseconds / 1000 /* milliseconds per second */,
            function () use ($deferred) {
                $deferred->resolve(null);
            }
        );

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        $deferred = new class() implements Deferred {
            public $reactDeferred;
            public $promise;

            public function getPromise(): Promise
            {
                return $this->promise;
            }

            public function resolve($value)
            {
                $this->reactDeferred->resolve($value);
            }

            public function reject(\Throwable $throwable)
            {
                $this->reactDeferred->reject($throwable);
            }
        };
        $deferred->reactDeferred = new \React\Promise\Deferred();
        $deferred->promise = self::fromReactPromise($deferred->reactDeferred->promise());

        return $deferred;
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addReadStream(
            $stream,
            function ($stream) use ($deferred) {
                $this->reactEventLoop->removeReadStream($stream);
                $deferred->resolve($stream);
            }
        );

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        $deferred = $this->deferred();
        $this->reactEventLoop->addWriteStream(
            $stream,
            function ($stream) use ($deferred) {
                $this->reactEventLoop->removeWriteStream($stream);
                $deferred->resolve($stream);
            }
        );

        return $deferred->getPromise();
    }

    private static function fromReactPromise(\React\Promise\PromiseInterface $reactPromise): Promise
    {
        $promise = new class() implements Promise {
            public $reactPromise;
        };
        $promise->reactPromise = $reactPromise;

        return $promise;
    }

    private static function toReactPromise(Promise $promise): \React\Promise\PromiseInterface
    {
        return $promise->reactPromise;
    }
}
