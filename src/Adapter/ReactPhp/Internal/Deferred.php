<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    /**
     * @var \React\Promise\Deferred
     */
    private $reactDeferred;

    /**
     * @var PromiseWrapper
     */
    private $promise;

    public function __construct()
    {
        $this->reactDeferred = new \React\Promise\Deferred();
        $this->promise = new PromiseWrapper($this->reactDeferred->promise());
    }

    public static function forAsync(): self
    {
        $self = new self();
        $self->promise->enableThrowOnDestructIfNotYielded();

        return $self;
    }

    /**
     * {@inheritdoc}
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    public function getPromiseWrapper(): PromiseWrapper
    {
        return $this->promise;
    }

    /**
     * {@inheritdoc}
     */
    public function resolve($value)
    {
        $this->reactDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable)
    {
        $this->reactDeferred->reject($throwable);
    }
}