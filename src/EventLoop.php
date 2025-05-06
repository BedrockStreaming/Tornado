<?php

declare(strict_types=1);

namespace M6Web\Tornado;

interface EventLoop
{
    /**
     * Waits the resolution of a promise, and returns its value.
     * You should use this function once for your global result.
     *
     * @template T
     *
     * @param Promise<T> $promise
     *
     * @return T
     */
    public function wait(Promise $promise);

    /**
     * Registers a generator in the event loop to execute it asynchronously.
     * The returned promise will be resolved with the value returned by the generator.
     *
     * @template T
     *
     * @param \Generator<int, Promise, mixed, T> $generator
     *
     * @return Promise<T>
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
     * @template TKey of array-key
     * @template TValue
     *
     * @param \Traversable<TKey, TValue>|array<TKey, TValue>                 $traversable Input elements
     * @param callable(TValue, TKey): \Generator<int, Promise, mixed, mixed> $function
     */
    public function promiseForeach($traversable, callable $function): Promise;

    /**
     * Creates a promise that will behave like the first settled input promise, while others will be ignored.
     **/
    public function promiseRace(Promise ...$promises): Promise;

    /**
     * Creates a promise already resolved with $value.
     *
     * @template T
     *
     * @param T $value
     *
     * @return Promise<T>
     */
    public function promiseFulfilled($value): Promise;

    /**
     * Creates a failing promise from an exception that will be thrown when trying to resolve the promise.
     */
    public function promiseRejected(\Throwable $throwable): Promise;

    /**
     * Creates a promise that will be resolved to null in a future tick of the event loop.
     *
     * @return Promise<null>
     */
    public function idle(): Promise;

    /**
     * Creates a promise that will be resolved to null after a fixed time.
     * ⚠️ The actual measured delay can be greater if your event loop is busy!
     * It also can be a little smaller, depending on your event loop (or system clock) accuracy.
     *
     * @return Promise<null>
     */
    public function delay(int $milliseconds): Promise;

    /**
     * Creates a deferred, allowing to create and resolve your own promises.
     */
    public function deferred(): Deferred;

    /**
     * Returns a promise that will be resolved with the input stream when it becomes readable.
     * ⚠️ Error handling (stream connection closed for example) might differ between implementations.
     *
     * @param resource $stream
     *
     * @return Promise<resource>
     */
    public function readable($stream): Promise;

    /**
     * Returns a promise that will be resolved with the input stream when it becomes writable.
     * ⚠️ Error handling (stream connection closed for example) might differ between implementations.
     *
     * @param resource $stream
     *
     * @return Promise<resource>
     */
    public function writable($stream): Promise;
}
