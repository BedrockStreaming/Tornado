<?php

namespace M6Web\Tornado\Adapter\Swoole;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Adapter\Swoole\Internal\SwooleDeferred;
use M6Web\Tornado\Adapter\Swoole\Internal\SwoolePromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\WaitGroup;
use Swoole\Event;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    /** @var Internal\StreamEventLoop */
    private $streamLoop;

    /** @var FailingPromiseCollection */
    private $unhandledFailingPromises;

    public function __construct()
    {
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

        Coroutine::create(function() use ($promise, &$value, &$isRejected, &$promiseSettled) {
            $ticks = 1;
            //$wg = new WaitGroup(1);
            $channel = new Channel($ticks);
            Internal\PromiseWrapper::toHandledPromise($promise, $this->unhandledFailingPromises)->getSwoolePromise()->then(
                function ($result) use (/* $wg , */$channel, &$value, &$promiseSettled) {
                    $promiseSettled = true;
                    $value = $result;
                    $channel->push(true);
                    //$wg->done();
                },
                function ($result) use (/* $wg , */$channel, &$value, &$isRejected, &$promiseSettled) {
                    $promiseSettled = true;
                    $value = $result;
                    $isRejected = true;
                    $channel->push(true);
                    //$wg->done();
                }
            );
            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();
            //$wg->wait();
        });
        while (!$promiseSettled) {
            // @codeCoverageIgnoreStart
            usleep(SwoolePromise::PROMISE_WAIT);
            // @codeCoverageIgnoreEnd
        }
        //Event::wait();
        //swoole_event_wait();

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
        $promises = array_map(function($promise) {
            if($promise instanceof Internal\PromiseWrapper) {
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
        /*return Internal\PromiseWrapper::createUnhandled(new SwoolePromise(function($resolve) {
            Coroutine::defer(function () use ($resolve) {
                //Coroutine::sleep(1.0);
                //usleep(1000000);
                $resolve(null);
            });
        }), $this->unhandledFailingPromises);*/
        return Internal\PromiseWrapper::createUnhandled(SwoolePromise::resolve(null), $this->unhandledFailingPromises, function() {
            return new SwoolePromise(function($resolve) {
                Coroutine::defer(function () use ($resolve) {
                    //Coroutine::sleep(1.0);
                    //usleep(1);
                    $resolve(null);
                });
            });
        });
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        return Internal\PromiseWrapper::createUnhandled(SwoolePromise::resolve(null), $this->unhandledFailingPromises, function() use ($milliseconds) {
            return new SwoolePromise(function($resolve) use ($milliseconds) {
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
