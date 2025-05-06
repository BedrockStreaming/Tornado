<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class Deferred implements \M6Web\Tornado\Deferred
{
    public function __construct(
        private readonly \React\Promise\Deferred $reactDeferred,
        private readonly PromiseWrapper $promise,
    ) {
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
    public function resolve(mixed $value): void
    {
        $this->reactDeferred->resolve($value);
    }

    /**
     * {@inheritdoc}
     */
    public function reject(\Throwable $throwable): void
    {
        $this->reactDeferred->reject($throwable);
    }
}
