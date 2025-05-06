<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 *
 * @implements Promise<TValue>
 */
class PromiseWrapper implements Promise
{
    /**
     * Use named (static) constructor instead
     *
     * @param \Amp\Promise<TValue> $ampPromise
     */
    private function __construct(
        private readonly \Amp\Promise $ampPromise,
        private bool $isHandled
    ) {
    }

    /**
     * @param \Amp\Promise<TValue> $ampPromise
     *
     * @return self<TValue>
     */
    public static function createUnhandled(\Amp\Promise $ampPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self($ampPromise, false);
        $promiseWrapper->ampPromise->onResolve(
            function (?\Throwable $reason, $value) use ($promiseWrapper, $failingPromiseCollection): void {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    /**
     * @param \Amp\Promise<TValue> $ampPromise
     *
     * @return self<TValue>
     */
    public static function createHandled(\Amp\Promise $ampPromise): self
    {
        return new self($ampPromise, true);
    }

    /**
     * @return \Amp\Promise<TValue>
     */
    public function getAmpPromise(): \Amp\Promise
    {
        return $this->ampPromise;
    }

    /**
     * @param Promise<TValue> $promise
     *
     * @return self<TValue>
     */
    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
