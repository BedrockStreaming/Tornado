<?php

declare(strict_types=1);

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
     */
    private function __construct(
        private readonly \Amp\Future $ampPromise,
        private bool $isHandled,
    ) {
    }

    /**
     * @return self<TValue>
     */
    public static function createUnhandled(\Amp\Future $ampPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self($ampPromise, false);
        $promiseWrapper->ampPromise->catch(
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection): void {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    /**
     * @return self<TValue>
     */
    public static function createHandled(\Amp\Future $ampPromise): self
    {
      $ampPromise->ignore();

        return new self($ampPromise, true);
    }

    public function getAmpFuture(): \Amp\Future
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
        $promise->getAmpFuture()->ignore();
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
