<?php

namespace M6Web\Tornado\Adapter\Swoole;

use function extension_loaded;
use M6Web\Tornado\Adapter\Swoole\Internal\DummyPromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use RuntimeException;
use Swoole\Coroutine;
use Swoole\Event;

class YieldEventLoop implements \M6Web\Tornado\EventLoop
{
    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException('EventLoop must running only with swoole extension.');
        }
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
        $value = null;
        $this->async((static function () use ($promise, &$value): \Generator {
            $value = yield $promise;
            Event::exit();
        })());
        Event::wait();

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {
        $fnWrapGenerator = function (\Generator $generator, Deferred $deferred) use (&$fnWrapGenerator) {
            Coroutine::create(function () use ($generator, $deferred, $fnWrapGenerator) {
                if (!$generator->valid()) {
                    $deferred->resolve($generator->getReturn());

                    return;
                }

                $promise = DummyPromise::wrap($generator->current());
                if ($this->isPending($promise)) {
                    $cid = Coroutine::getCid();
                    $promise->addCallback(function () use ($cid) {
                        Coroutine::resume($cid);
                    });
                    Coroutine::yield();
                }
                $generator->send($promise->getValue());
                $fnWrapGenerator($generator, $deferred);
            });
        };

        $deferred = $this->deferred();
        $fnWrapGenerator($generator, $deferred);

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseAll(Promise ...$promises): Promise
    {
        $wg = new Coroutine\WaitGroup();
        $result = [];
        foreach ($promises as $index => $promise) {
            $this->async((static function () use ($wg, &$result, $index, $promise) {
                $wg->add();
                $result[$index] = yield $promise;
                $wg->done();
            })());
        }

        $deferred = $this->deferred();
        Coroutine::create(function () use ($wg, $deferred, &$result) {
            $wg->wait();
            $deferred->resolve($result);
        });

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function promiseForeach($traversable, callable $function): Promise
    {
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRace(Promise ...$promises): Promise
    {
    }

    /**
     * {@inheritdoc}
     */
    public function promiseFulfilled($value): Promise
    {
        $promise = new DummyPromise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        $promise = new DummyPromise();
        $promise->reject($throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $deferred = $this->deferred();
        Coroutine::create(function () use ($deferred) {
            Coroutine::defer(function () use ($deferred) {
                usleep(1000);
                $deferred->resolve(null);
            });
        });

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $deferred = $this->deferred();
        Coroutine::create(function () use ($milliseconds, $deferred) {
            //Coroutine::sleep($milliseconds / 1000);
            usleep($milliseconds * 1000);
            $deferred->resolve(null);
        });

        return $deferred->getPromise();
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {
        return new DummyPromise();
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
