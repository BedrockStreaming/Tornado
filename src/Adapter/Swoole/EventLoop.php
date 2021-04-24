<?php

namespace M6Web\Tornado\Adapter\Swoole;

use function extension_loaded;
use Generator;
use M6Web\Tornado\Adapter\Swoole\Internal\DummyPromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;
use Swoole\IDEHelper\StubGenerators\Swoole;
use Swoole\Process;
use Throwable;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private $cids;
    private $oldCids;
    private $pendingThrowPromises;

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('EventLoop must running only with swoole extension.');
        }

        $this->cids = [];
        $this->oldCids = [];
        $this->pendingThrowPromises = [];
    }

    public function __destruct()
    {
        /*foreach ($this->cids as $cid) {
            if(Coroutine::exists($cid)) {
                //Coroutine::resume($cid);
                $pid = Coroutine::getPcid($cid);
                Process::kill($pid);
                Process::wait();
            }
        }*/

        /*foreach ($this->oldCids as $cid) {
            if(Coroutine::exists($cid)) {
                //Coroutine::resume($cid);
                $pid = Coroutine::getPcid($cid);
                Process::kill($pid);
                Process::wait();
            }
        }*/

        //Process::signal(SIGTERM, function() {
        //    swoole_event_exit();
        //});
        //Event::exit();
    }

    private function shiftCoroutine(): void
    {
        if (count($this->cids) === 0) {
            return;
        }

        $cid = array_shift($this->cids);
        if (Coroutine::exists($cid)) {
            $this->oldCids[] = $cid;
            Coroutine::resume($cid);
        } else {
            $this->shiftCoroutine();
        }
    }

    private function pushCoroutine(): void
    {
        $cid = Coroutine::getCid();
        $this->cids[] = $cid;
        Coroutine::yield();

    }

    private function createPromise(): DummyPromise
    {
        return new DummyPromise(function (DummyPromise $promise) {
            $this->shiftCoroutine();
        });
    }

    private function getValue($value)
    {
        if ($value instanceof DummyPromise && !$this->isPending($value)) {
            if ($value->getException() !== null) {
                foreach ($this->pendingThrowPromises as $index => $promise) {
                    if ($promise === $value) {
                        unset($this->pendingThrowPromises[$index]);
                    }
                }
                throw $value->getException();
            }

            $value = $this->getValue($value->getValue());
        }

        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->getValue($v);
            }
        }

        foreach ($this->pendingThrowPromises as $p) {
            $this->getValue($p);
        }

        return $value;
    }

    private function isPending(DummyPromise $promise): bool
    {
        if ($promise->isPending()) {
            return $promise->isPending();
        }

        if ($promise->getException() === null) {
            if ($promise->getValue() instanceof DummyPromise) {
                return $promise->getValue()->isPending();
            }

            if (is_array($promise->getValue())) {
                foreach ($promise->getValue() as $value) {
                    if ($value instanceof DummyPromise && $value->isPending()) {
                        return $value->isPending();
                    }
                }
            }
        }

        return $promise->isPending();
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $promise = DummyPromise::wrap($promise);

        if (count($this->cids) === 0 && $this->isPending($promise)) {
            throw new \Error('Impossible to resolve the promise, no more task to execute..');
        }

        while ($this->isPending($promise)) {
            $this->shiftCoroutine();
        }

        return $this->getValue($promise);
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
                        if (!$promise instanceof DummyPromise) {
                            throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
                        }
                        $this->pushCoroutine();
                        $generator->send($this->getValue($promise));
                    }

                    $deferred->resolve($generator->getReturn());
                } catch (Throwable $exception) {
                    try {
                        $generator->throw($exception);
                        $fnWrapGenerator($generator, $deferred);
                    } catch (\Throwable $throwable) {
                        $this->pendingThrowPromises[] = $deferred;
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
        $ticks = count($promises);
        if ($ticks === 0) {
            return $this->promiseFulfilled([]);
        }

        $deferred = $this->createPromise();
        $result = [array_fill(0, $ticks, false)];

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = static function (int $index, Promise $promise) use ($deferred, &$ticks, &$result): Generator {
            try {
                $result[$index] = yield $promise;
            } catch (Throwable $throwable) {
                // Prevent to reject the globalPromise twice
                if ($ticks > 0) {
                    $ticks = -1;
                    $deferred->reject($throwable);

                    return;
                }
            }

            // Last resolved promise resolved globalPromise
            if (--$ticks === 0) {
                $deferred->resolve($result);
            }
        };

        foreach ($promises as $index => $promise) {
            $this->async($waitOnePromise($index, $promise));
        }

        return $deferred;
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

        $deferred = $this->createPromise();

        foreach ($promises as $promise) {
            DummyPromise::wrap($promise)->addCallback(function (DummyPromise $promise) use ($deferred) {
                if ($deferred->isPending()) {
                    if ($promise->getException() !== null) {
                        $deferred->reject($promise->getException());
                    } else {
                        $deferred->resolve($promise->getValue());
                    }
                }
            });
        }

        return $deferred;
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
        $promise = $this->createPromise();
        Coroutine::create(function () use ($promise) {
            $this->pushCoroutine();
            // Coroutine::defer(function () use ($promise) {
            $promise->resolve(null);
            // });
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $promise = $this->createPromise();
        Coroutine::create(function () use ($milliseconds, $promise) {
            $this->pushCoroutine();
            // Coroutine::sleep($milliseconds / 1000);
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
