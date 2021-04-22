<?php

namespace M6Web\Tornado\Adapter\Swoole;

use Generator;
use M6Web\Tornado\Adapter\Swoole\Internal\DummyPromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Swoole\Coroutine;
use RuntimeException;
use Swoole\IDEHelper\StubGenerators\Swoole;
use Swoole\Runtime;
use Throwable;
use function extension_loaded;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private $cids;

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'EventLoop must running only with swoole extension.'
            );
        }

        $this->cids = [];
    }

    private function shiftCoroutine(): mixed
    {
        if(count($this->cids) === 0) {
            return null;
        }

        $cid = array_shift($this->cids);
        if(Coroutine::exists($cid)) {
            Coroutine::resume($cid);
        } else {
            $this->shiftCoroutine();
        }

        return $cid;
    }

    private function pushCoroutine(): mixed
    {
        $cid = Coroutine::getCid();
        $this->cids[] = $cid;
        Coroutine::yield();
        return $cid;
    }

    private function createPromise(): DummyPromise
    {
        return new DummyPromise(function () {
            $this->shiftCoroutine();
        });
    }

    private function resolve($value)
    {
        if($value instanceof DummyPromise && !$value->isPending()) {
            if($value->getException() !== null) {
                throw $value->getException();
            }

            $value = $this->resolve($value->getValue());
        }

        if(is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->resolve($v);
            }
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise): mixed
    {
        $promise = DummyPromise::wrap($promise);

        if(count($this->cids) === 0 && $promise->isPending()) {
            throw new \Error('Impossible to resolve the promise, no more task to execute..');
        }

        while ($promise->isPending()) {
            $this->shiftCoroutine();
        }

        return $this->resolve($promise);
    }

    /**
     * {@inheritdoc}
     */
    public function async(Generator $generator): Promise
    {
        $fnWrapGenerator = function (Generator $generator, $deferred) use (&$fnWrapGenerator) {
            Coroutine::create(function () use ($generator, $deferred, $fnWrapGenerator) {
                try {
                    while ($generator->valid()) {
                        $promise = $generator->current();
                        if(!$promise instanceof DummyPromise) {
                            throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
                        }
                        $this->pushCoroutine();
                        $generator->send($this->resolve($promise));
                    }

                    $deferred->resolve($generator->getReturn());
                } catch (Throwable $exception) {
                    try {
                        $generator->throw($exception);
                        $fnWrapGenerator($generator, $deferred);
                    } catch (\Throwable $throwable) {
                        $deferred->reject($throwable);
                    }
                }
            });
        };

        $deferred = $this->createPromise();
        $fnWrapGenerator($generator, $deferred);

        return $deferred;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $nbPromises = count($promises);
        if ($nbPromises === 0) {
            return $this->promiseFulfilled([]);
        }

        $globalPromise = $this->createPromise();
        $allResults = array_fill(0, $nbPromises, false);

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = static function (int $index, Promise $promise) use ($globalPromise, &$nbPromises, &$allResults): Generator {
            try {
                $allResults[$index] = yield $promise;
            } catch (Throwable $throwable) {
                // Prevent to reject the globalPromise twice
                if ($nbPromises > 0) {
                    $nbPromises = -1;
                    $globalPromise->reject($throwable);

                    return;
                }
            }

            // Last resolved promise resolved globalPromise
            if (--$nbPromises === 0) {
                $globalPromise->resolve($allResults);
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($waitOnePromise($index, $promise));
        }

        return $globalPromise;
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

        $globalPromise = $this->createPromise();
        $isFirstPromise = true;

        $wrapPromise = function (Promise $promise) use ($globalPromise, &$isFirstPromise): \Generator {
            try {
                $result = yield $promise;
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->resolve($result);
                }
            } catch (\Throwable $throwable) {
                if ($isFirstPromise) {
                    $isFirstPromise = false;
                    $globalPromise->reject($throwable);
                }
            }
        };

        foreach ($promises as $promise) {
            $this->async($wrapPromise($promise));
        }

        return $globalPromise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        $promise = $this->createPromise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(Throwable $throwable): Promise
    {
        $promise = $this->createPromise();
        $promise->reject($throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $promise = new DummyPromise();
        Coroutine::create(function() use ($promise) {
            $this->pushCoroutine();
            $promise->resolve(null);
            //Coroutine::defer(function () use ($promise) {
            //});
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $promise = new DummyPromise();
        Coroutine::create(function() use($milliseconds, $promise) {
            $this->pushCoroutine();
            //Coroutine::sleep($milliseconds / 1000);
            usleep($milliseconds * 1000);
            $promise->resolve(null);
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return $this->createPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
        return $this->promiseFulfilled($stream);
    }
}
