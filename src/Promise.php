<?php

namespace M6Web\Tornado;

/**
 * To resolve the value of a promise, you have to yield it from a generator registered in the event loop.
 */
interface Promise
{
    public function cancel();
}
