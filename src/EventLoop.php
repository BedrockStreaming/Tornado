<?php

namespace M6Web\Tornado;

interface EventLoop
{
    /**
     * Waits the resolution of a promise, and returns its value.
     * You should use this function once for your global result.
     *
     * @return mixed
     */
    public function wait(Promise $promise);

    /**
     * Registers a generator in the event loop to executes it asynchronously.
     * The returned promise will be resolved with the value returned by the generator.
     */
    public function async(\Generator $generator): Promise;

    /**
     * Creates a promise that will be resolved with an array of all sub-promises results.
     */
    public function promiseAll(Promise ...$promises): Promise;

    /**
     * Creates a promise already resolved with $value.
     */
    public function promiseFulfilled($value): Promise;

    /**
     * Creates a failing promise from an exception that will be thrown when trying to resolve the promise.
     */
    public function promiseRejected(\Throwable $throwable): Promise;

    /**
     * Creates a promise that will be resolved to null in a future tick of the event loop.
     */
    public function idle(): Promise;

    /**
     * Creates a deferred, allowing to create and resolve your own promises.
     */
    public function deferred(): Deferred;
}
