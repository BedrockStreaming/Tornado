<?php

namespace M6Web\Tornado;

/**
 * To resolve the value of a promise, you have to yield it from a generator registered in the event loop.
 */
interface Promise
{
    /**
     * Requests the cancellation of the promise.
     * Functions still yielding for this promise will receive a `CancelledException`.
     */
    public function cancel(): void;
}
