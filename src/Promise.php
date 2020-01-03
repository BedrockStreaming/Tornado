<?php

namespace M6Web\Tornado;

/**
 * To resolve the value of a promise, you have to yield it from a generator registered in the event loop.
 */
interface Promise
{
    /**
     * Requests the cancellation of the promise.
     * Support of cancellation may vary according to the function providing the promise,
     * check corresponding documentation for more information.
     * Functions still yielding for this promise will receive the provided `CancellationException`.
     * Cancelling a promise already resolved/rejected/cancelled has no effect.
     */
    public function cancel(CancellationException $exception): void;
}
