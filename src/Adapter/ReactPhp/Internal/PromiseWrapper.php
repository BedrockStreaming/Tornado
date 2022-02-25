<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 *
 * @template TValue
 * @implements Promise<TValue>
 */
class PromiseWrapper implements Promise
{
    /** @var \React\Promise\PromiseInterface */
    private $reactPromise;

    /** @var bool */
    private $isHandled;

    /**
     * Use named (static) constructor instead
     *
     * @param \React\Promise\PromiseInterface $reactPromise
     */
    private function __construct(\React\Promise\PromiseInterface $reactPromise, bool $isHandled)
    {
        $this->reactPromise = $reactPromise;
        $this->isHandled = $isHandled;
    }

    public static function createUnhandled(\React\Promise\PromiseInterface $reactPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self($reactPromise, false);
        $promiseWrapper->reactPromise->then(
            null,
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection) {
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
