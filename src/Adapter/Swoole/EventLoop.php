<?php

namespace M6Web\Tornado\Adapter\Swoole;

use M6Web\Tornado\Adapter\Swoole\Internal\YieldPromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Swoole\Coroutine;
use Swoole\Event;
use RuntimeException;
use function extension_loaded;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'SwoolePromise MUST running only in CLI mode with swoole extension.'
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {
        $value = null;
        $this->async((static function() use($promise, &$value): \Generator {
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
        Coroutine::create(function() use($generator, $generatorPromise) {
            while($generator->valid()) {
                $promise = $generator->current();
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
    }

    /**
     * {@inheritdoc}
     */
    public function promiseRejected(\Throwable $throwable): Promise
    {
    }

    /**
     * {@inheritdoc}
     */
    public function idle(): Promise
    {
        $promise = new YieldPromise();
        Coroutine::create(function() use ($promise) {
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
        Coroutine::create(function() use($milliseconds, $promise) {
            Coroutine::sleep($milliseconds / 1000 /* ms -> s */);
            $promise->resolve(null);
        });

        return $promise;
    }

    /**
     * {@inheritdoc}
     */
    public function deferred(): Deferred
    {

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
