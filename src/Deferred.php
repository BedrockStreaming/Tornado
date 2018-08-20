<?php

namespace M6\Front\Async;

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
    public function resolve($value);

    /**
     * Rejects associated promise.
     */
    public function reject(\Throwable $throwable);
}
