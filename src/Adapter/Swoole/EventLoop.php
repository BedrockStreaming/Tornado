<?php

namespace M6Web\Tornado\Adapter\Swoole;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Adapter\Swoole\Internal\SwooleDeferred;
use M6Web\Tornado\Adapter\Swoole\Internal\SwoolePromise;
use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;
use Swoole\Coroutine;
use Swoole\Event;
use RuntimeException;
use function extension_loaded;

class EventLoop implements \M6Web\Tornado\EventLoop
{
    private $cids;

    public function __construct()
    {
        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'SwoolePromise MUST running only in CLI mode with swoole extension.'
            );
        }

        $this->cids = [];
    }

    /**
     * {@inheritdoc}
     */
    public function wait(Promise $promise)
    {

    }

    /**
     * {@inheritdoc}
     */
    public function async(\Generator $generator): Promise
    {

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

    }

    /**
     * {@inheritdoc}
     */
    public function delay(int $milliseconds): Promise
    {

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
