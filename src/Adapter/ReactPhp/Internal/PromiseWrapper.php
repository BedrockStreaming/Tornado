<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /**
     * @var \React\Promise\PromiseInterface
     */
    private $reactPromise;

    /**
     * @var \Throwable
     */
    private $exception;

    private $hasBeenYielded = false;

    private $throwOnDestructIfNotYielded = false;

    public function __construct(\React\Promise\PromiseInterface $reactPromise)
    {
        $this->reactPromise = $reactPromise;
        $this->reactPromise->then(null, function (\Throwable $reason) {
            $this->exception = $reason;
        });
    }

    public function __destruct()
    {
        if ($this->throwOnDestructIfNotYielded && !$this->hasBeenYielded && $this->exception !== null) {
            throw $this->exception;
        }
    }

    public function enableThrowOnDestructIfNotYielded()
    {
        $this->throwOnDestructIfNotYielded = true;
    }

    public function getReactPromise(): \React\Promise\PromiseInterface
    {
        return $this->reactPromise;
    }

    public static function downcast(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        return $promise;
    }

    public static function toWatchedReactPromise(Promise $promise): \React\Promise\PromiseInterface
    {
        $promise = self::downcast($promise);
        $promise->hasBeenYielded = true;

        return $promise->reactPromise;
    }

    public static function fromGenerator(\Generator $generator): self
    {
        $promise = $generator->current();
        if (!$promise instanceof self) {
            throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
        }

        $promise = self::downcast($promise);
        $promise->hasBeenYielded = true;

        return $promise;
    }

    /**
     * @param Promise[] ...$promises
     *
     * @return \React\Promise\PromiseInterface[]
     */
    public static function toWatchedReactPromiseArray(Promise ...$promises): array
    {
        return array_map(function (Promise $promise) {
            $promise = self::downcast($promise);
            $promise->hasBeenYielded = true;

            return $promise->reactPromise;
        }, $promises);
    }
}
