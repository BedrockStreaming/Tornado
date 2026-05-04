<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Amp\Internal;

use Amp\Future;
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
     * @param Future<TValue> $ampPromise
     */
    private function __construct(
        private readonly Future $ampPromise,
        private bool $isHandled,
    ) {
    }

    /**
     * @return self<TValue>
     */
    public static function createUnhandled(Future $ampPromise, FailingPromiseCollection $failingPromiseCollection): self
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
    public static function createHandled(Future $ampPromise): self
    {
        $ampPromise->ignore();

        return new self($ampPromise, true);
    }

    /**
     * @return Future<TValue>
     */
    public function getAmpFuture(): Future
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
