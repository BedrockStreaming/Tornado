<?php

declare(strict_types=1);

namespace M6Web\Tornado;

/**
 * A deferred allows you to promise something, and to resolve/fail it later.
 */
interface Deferred
{
    /**
     * The promise managed by this deferred.
     */
    public function getPromise(): Promise;

    /**
     * Resolves associated promise.
     */
    public function resolve(mixed $value): void;

    /**
     * Rejects associated promise.
     */
    public function reject(\Throwable $throwable): void;
}
