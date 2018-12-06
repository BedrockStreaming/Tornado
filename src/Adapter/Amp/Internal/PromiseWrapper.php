<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /**
     * @var \Amp\Promise
     */
    private $ampPromise;

    /**
     * @var ?\Throwable
     */
    private $exception;

    private $hasBeenYielded = false;

    private $throwOnDestructIfNotYielded = false;

    public function __construct(\Amp\Promise $ampPromise)
    {
        $this->ampPromise = $ampPromise;
        $this->ampPromise->onResolve(function (?\Throwable $reason, $value) {
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

    public function getAmpPromise(): \Amp\Promise
    {
        return $this->ampPromise;
    }

    public static function downcast(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        return $promise;
    }

    public static function toWatchedAmpPromise(Promise $promise): \Amp\Promise
    {
        $promise = self::downcast($promise);
        $promise->hasBeenYielded = true;

        return $promise->getAmpPromise();
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
     * @return \Amp\Promise[]
     */
    public static function toWatchedAmpPromiseArray(Promise ...$promises): array
    {
        return array_map(function (Promise $promise) {
            $promise = self::downcast($promise);
            $promise->hasBeenYielded = true;

            return $promise->ampPromise;
        }, $promises);
    }
}
