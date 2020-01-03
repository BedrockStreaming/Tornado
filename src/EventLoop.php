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
     * Registers a generator in the event loop to execute it asynchronously.
     * The returned promise will be resolved with the value returned by the generator.
     * When cancelled, the generator is removed from event loop and current generator promise is also cancelled.
     */
    public function async(\Generator $generator): Promise;

    /**
     * Creates a promise that will be resolved with an array of all sub-promises results.
     * When cancelled, all provided promises are also cancelled.
     */
    public function promiseAll(Promise ...$promises): Promise;

    /**
     * Creates a Promise that will be resolved with an array containing the result of
     * $function applied to each elements of input traversable.
     * You should use this function each time that you use yield in a foreach loop.
     * When cancelled, all sub-promises (created for $traversable elements) are also cancelled.
     *
     * @param \Traversable|array $traversable Input elements
     * @param callable           $function    must return a generator from an input value, and an optional key
     */
    public function promiseForeach($traversable, callable $function): Promise;

    /**
     * Creates a promise that will behave like the first settled input promise, while others will be ignored and cancelled.
     * When cancelled, all provided promises are also cancelled.
     **/
    public function promiseRace(Promise ...$promises): Promise;

    /**
     * Creates a promise already resolved with $value.
     * Cannot be cancelled since already fulfilled.
     */
    public function promiseFulfilled($value): Promise;

    /**
     * Creates a failing promise from an exception that will be thrown when trying to resolve the promise.
     * Cannot be cancelled since already rejected.
     */
    public function promiseRejected(\Throwable $throwable): Promise;

    /**
     * Creates a promise that will be resolved to null in a future tick of the event loop.
     * Can be cancelled.
     */
    public function idle(): Promise;

    /**
     * Creates a promise that will be resolved to null after a fixed time.
     * ⚠️ The actual measured delay can be greater if your event loop is busy!
     * It also can be a little smaller, depending on your event loop (or system clock) accuracy.
     * Can be cancelled.
     */
    public function delay(int $milliseconds): Promise;

    /**
     * Creates a deferred, allowing to create and resolve your own promises.
     *
     * @param callable $cancelCallback  When the promise attached to the deferred is cancelled this function is called
     *                                  to clean up all related jobs in progress.
     *                                  It can accept a CancellableException in parameter.
     */
    public function deferred(callable $cancelCallback): Deferred;

    /**
     * Returns a promise that will be resolved with the input stream when it becomes readable.
     *
     * @param resource $stream
     */
    public function readable($stream): Promise;

    /**
     * Returns a promise that will be resolved with the input stream when it becomes writable.
     *
     * @param resource $stream
     */
    public function writable($stream): Promise;
}
