<?php

namespace M6Web\Tornado\Adapter\Swoole;

use function extension_loaded;
use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Adapter\Swoole\Internal\SwooleDeferred;
use M6Web\Tornado\Adapter\Swoole\Internal\SwoolePromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;

class PromiseEventLoop implements \M6Web\Tornado\EventLoop
{
    /** @var Internal\StreamEventLoop */
    private $streamLoop;

    /** @var FailingPromiseCollection */
    private $unhandledFailingPromises;

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('SwoolePromise MUST running only in CLI mode with swoole extension.');
        }

        $this->streamLoop = new Internal\StreamEventLoop();
        $this->unhandledFailingPromises = new FailingPromiseCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $value = null;
        $isRejected = false;
        $promiseSettled = false;

        Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getSwoolePromise()->then(
            function ($result) use (&$value, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                Event::exit();
            },
            function ($result) use (&$value, &$isRejected, &$promiseSettled) {
                $promiseSettled = true;
                $value = $result;
                $isRejected = true;
                Event::exit();
            }
        );
        if (!$promiseSettled) {
            Event::wait();
        }

        if (!$promiseSettled) {
            throw new \Error('Impossible to resolve the promise, no more task to execute.');
        }

        if ($isRejected) {
            /* @var $value \Exception */
            throw $value;
        }

        $this->unhandledFailingPromises->throwIfWatchedFailingPromiseExists();

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $fnWrapGenerator = function (\Generator $generator, SwooleDeferred $deferred) use (&$fnWrapGenerator) {
            try {
                if (!$generator->valid()) {
                    $deferred->resolve($generator->getReturn());

                    return;
                }
                $promise = $generator->current();
                if (!$promise instanceof Internal\PromiseWrapper) {
                    throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
                }
                Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)
                    ->getSwoolePromise()->then(
                        function ($result) use ($generator, $deferred, $fnWrapGenerator) {
                            try {
                                $generator->send($result);
                                $fnWrapGenerator($generator, $deferred);
                            } catch (\Throwable $throwable) {
                                $deferred->reject($throwable);
                            }
                        },
                        function ($reason) use ($generator, $deferred, $fnWrapGenerator) {
                            try {
                                $generator->throw($reason);
                                $fnWrapGenerator($generator, $deferred);
                            } catch (\Throwable $throwable) {
                                $deferred->reject($throwable);
                            }
                        }
                    );
            } catch (\Throwable $throwable) {
                $deferred->reject($throwable);
            }
        };

        $deferred = new SwooleDeferred();
        $fnWrapGenerator($generator, $deferred);

        return Internal\PromiseWrapper::createUnhandled($deferred->getSwoolePromise(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $promises = array_map(function ($promise) {
            if ($promise instanceof Internal\PromiseWrapper) {
                return $promise->getSwoolePromise();
            }

            return SwoolePromise::resolve($promise);
        }, $promises);

        return Internal\PromiseWrapper::createHandled(SwoolePromise::all($promises));
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

        $deferred = new SwooleDeferred();
        $isFirstPromise = true;

        $wrapPromise = function (SwoolePromise $promise) use ($deferred, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $deferred->reject($throwable);
                }
            }
        };

        $promises = array_map(
            function (Promise $promise) {
                return Internal\PromiseWrapper::toHandledPromise(
                    $promise,
                    $this->unhandledFailingPromises
                )->getSwoolePromise();
            },
            $promises
        );

        foreach ($promises as $promise) {
            SwoolePromise::resolve($wrapPromise($promise));
        }

        return Internal\PromiseWrapper::createUnhandled($deferred->getSwoolePromise(), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        return Internal\PromiseWrapper::createHandled(SwoolePromise::resolve($value));
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        return Internal\PromiseWrapper::createHandled(SwoolePromise::reject($throwable));
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        return Internal\PromiseWrapper::createUnhandled(new SwoolePromise(function ($resolve) {
            Coroutine::defer(function () use ($resolve) {
                //Coroutine::sleep(0.001);
                $resolve(null);
            });
        }), $this->unhandledFailingPromises);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        return Internal\PromiseWrapper::createUnhandled(SwoolePromise::resolve(null), $this->unhandledFailingPromises, function () use ($milliseconds) {
            return new SwoolePromise(function ($resolve) use ($milliseconds) {
                //Coroutine::sleep($milliseconds / 1000000);
                usleep($milliseconds * 1000);
                $resolve(null);
            });
        });
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new Internal\Deferred(
            $deferred = new SwooleDeferred(),
            // Manually created promises are considered as handled.
            Internal\PromiseWrapper::createHandled($deferred->getSwoolePromise())
        );
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->streamLoop->readable($this, $stream);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->streamLoop->writable($this, $stream);
    }
}
