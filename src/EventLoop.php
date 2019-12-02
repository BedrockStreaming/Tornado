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
     */
    public function async(\Generator $generator): Promise;

    /**
     * Creates a promise that will be resolved with an array of all sub-promises results.
     */
    public function promiseAll(Promise ...$promises): Promise;

    /**
     * Creates a Promise that will be resolved with an array containing the result of
     * $function applied to each elements of input traversable.
     * You should use this function each time that you use yield in a foreach loop.
     *
     * @param \Traversable|array $traversable Input elements
     * @param callable           $function    must return a generator from an input value, and an optional key
     */
    public function promiseForeach($traversable, callable $function): Promise;

    /**
     * Creates a promise that will behave like the first settled input promise, while others will be ignored.
     **/
    public function promiseRace(Promise ...$promises): Promise;

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
     * Creates a promise that will be resolved to null after a fixed time.
     * ⚠️ The actual measured delay can be greater if your event loop is busy!
     * It also can be a little smaller, depending on your event loop (or system clock) accuracy.
     */
    public function delay(int $milliseconds): Promise;

    /**
     * Creates a deferred, allowing to create and resolve your own promises.
     */
    public function deferred(callable $canceller = null): Deferred;

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
