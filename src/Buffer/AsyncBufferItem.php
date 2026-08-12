<?php

declare(strict_types=1);

namespace M6Web\Tornado\Buffer;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\Promise;

/**
 * Capture some arguments to be resolved later
 */
class AsyncBufferItem implements Deferred
{
    private bool $isPending = true;

    public function __construct(
        private readonly array    $args,
        private readonly Deferred $deferred,
    ) {
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getPromise(): Promise
    {
        return $this->deferred->getPromise();
    }

    public function isPending(): bool
    {
        return $this->isPending;
    }

    public function resolve(mixed $value): void
    {
        $this->isPending = false;
        $this->deferred->resolve($value);
    }

    public function reject(\Throwable $throwable): void
    {
        $this->isPending = false;
        $this->deferred->reject($throwable);
    }
}
