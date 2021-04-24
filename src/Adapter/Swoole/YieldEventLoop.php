<?php

namespace M6Web\Tornado\Adapter\Swoole;

use function extension_loaded;
use JetBrains\PhpStorm\Pure;
use M6Web\Tornado\Adapter\Swoole\Internal\YieldPromise;
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
        $generatorPromise = new YieldPromise();
        Coroutine::create(function () use ($generator, $generatorPromise) {
            while ($generator->valid()) {
                $promise = YieldPromise::wrap($generator->current());
                $promise->yield();
                $generator->send($promise->value());
            }

            $generatorPromise->resolve($generator->getReturn());
        });

        return $generatorPromise;
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

        $globalPromise = new YieldPromise();
        $allResults = array_fill(0, $nbPromises, false);

        // To ensure that the last resolved promise resolves the global promise immediately
        $waitOnePromise = function (int $index, Promise $promise) use ($globalPromise, &$nbPromises, &$allResults): \Generator {
            try {
                $allResults[$index] = yield $promise;
            } catch (\Throwable $throwable) {
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
        $promise = new YieldPromise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
        $promise = new YieldPromise();
        $promise->reject($throwable);

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $promise = new YieldPromise();
        Coroutine::create(function () use ($promise) {
            Coroutine::defer(function () use ($promise) {
                $promise->resolve(null);
            });
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {
        $promise = new YieldPromise();
        Coroutine::create(function () use ($milliseconds, $promise) {
            Coroutine::sleep($milliseconds / 1000);
            $promise->resolve(null);
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    #[Pure]
 public function deferred(): Deferred
 {
     return new YieldPromise();
 }

    /**
     * {@inheritdoc}
     */
    public function readable($stream): Promise
    {
    }

    /**
     * {@inheritdoc}
     */
    public function writable($stream): Promise
    {
    }
}
