<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

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
        private readonly \React\Promise\PromiseInterface $reactPromise,
        private bool $isHandled,
    ) {
    }

    public static function createUnhandled(\React\Promise\PromiseInterface $reactPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self($reactPromise, false);
        $promiseWrapper->reactPromise->then(
            null,
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection): void {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    public static function createHandled(\React\Promise\PromiseInterface $reactPromise): self
    {
        return new self($reactPromise, true);
    }

    public function getReactPromise(): \React\Promise\PromiseInterface
    {
        return $this->reactPromise;
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
