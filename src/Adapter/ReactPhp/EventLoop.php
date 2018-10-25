<?php

namespace M6Web\Tornado\Adapter\ReactPhp;

use M6Web\Tornado\Adapter\ReactPhp\Internal\PromiseWrapper;
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
        $promiseSettled = false;
        Internal\PromiseWrapper::downcast($promise)->getReactPromise()->then(
            function ($result) use (&$value, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                $this->reactEventLoop->stop();
            },
            function ($result) use (&$value, &$isRejected, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                $isRejected = true;
                $this->reactEventLoop->stop();
            }
        );

        if (!$promiseSettled) {
            $this->reactEventLoop->run();
        }

        if (!$promiseSettled) {
            throw new \Error('Impossible to resolve the promise, no more task to execute.');
        }

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
        $fnWrapGenerator = function (\Generator $generator, callable $fnSuccess, callable $fnFailure) use (&$fnWrapGenerator) {
            try {
                if (!$generator->valid()) {
                    return $fnSuccess($generator->getReturn());
                }
                Internal\PromiseWrapper::fromGenerator($generator)
                    ->getReactPromise()->then(
                        function ($result) use ($generator, $fnSuccess, $fnFailure, $fnWrapGenerator) {
                            try {
                                $generator->send($result);
                                $fnWrapGenerator($generator, $fnSuccess, $fnFailure);
                            } catch (\Throwable $throwable) {
                                $fnFailure($throwable);
                            }
                        },
                        function ($reason) use ($generator, $fnSuccess, $fnFailure, $fnWrapGenerator) {
                            try {
                                $generator->throw($reason);
                                $fnWrapGenerator($generator, $fnSuccess, $fnFailure);
                            } catch (\Throwable $throwable) {
                                $fnFailure($throwable);
                            }
                        }
                    );
            } catch (\Throwable $throwable) {
                $fnFailure($throwable);
            }
        };

        $deferred = new Internal\Deferred();
        $fnWrapGenerator(
            $generator,
            [$deferred, 'resolve'],
            function (\Throwable $throwable) use ($deferred) {
                if ($deferred->getPromiseWrapper()->hasBeenYielded()) {
                    $deferred->reject($throwable);
                } else {
                    $this->reactEventLoop->futureTick(function () use ($throwable) {
                        throw $throwable;
                    });
                }
            }
        );

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        return new Internal\PromiseWrapper(\React\Promise\all(
            Internal\PromiseWrapper::toReactPromiseArray(...$promises)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
        $reactPromises = [];
        foreach ($traversable as $key => $value) {
            $reactPromises[] = Internal\PromiseWrapper::downcast(
                $this->async($function($value, $key))
            )->getReactPromise();
        }

        return new PromiseWrapper(\React\Promise\all($reactPromises));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
        return new Internal\PromiseWrapper(\React\Promise\race(
            Internal\PromiseWrapper::toReactPromiseArray(...$promises)
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return new Internal\PromiseWrapper(new \React\Promise\FulfilledPromise($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return new Internal\PromiseWrapper(new \React\Promise\RejectedPromise($throwable));
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
        return new Internal\Deferred();
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
}
