<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /**
     * @var \Amp\Deferred
     */
    private $ampDeferred;

    /**
     * @var PromiseWrapper
     */
    private $promise;

    public function __construct()
    {
        $this->ampDeferred = new \Amp\Deferred();
        $this->promise = new PromiseWrapper($this->ampDeferred->promise());
    }

    /**
     * {@inheritdoc}
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($value)
    {
        $this->ampDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable)
    {
        $this->ampDeferred->fail($throwable);
    }
}
