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
    private bool $isSettled = false;
    private mixed $value = null;
    private ?\Throwable $error = null;

    /**
     * Use named (static) constructor instead
     *
     * @param Future<TValue> $ampFuture
     */
    private function __construct(
        public readonly Future $ampFuture,
        private bool $isHandled,
    ) {
    }

    /**
     * @return self<TValue>
     */
    public static function createUnhandled(Future $ampPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self($ampPromise, false);
        $promiseWrapper->ampFuture->catch(
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
     * @return self<TValue>
     */
    public static function createFulfilled(mixed $value): self
    {
        $promise = self::createHandled(Future::complete($value));
        $promise->settle(null, $value);

        return $promise;
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
        $promise->ampFuture->ignore();
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }

    public function getError(): ?\Throwable
    {
        return $this->error;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function isSettled(): bool
    {
        return $this->isSettled;
    }

    public function settle(?\Throwable $error, mixed $value): void
    {
        $this->isSettled = true;
        $this->error = $error;
        $this->value = $value;
    }
}
