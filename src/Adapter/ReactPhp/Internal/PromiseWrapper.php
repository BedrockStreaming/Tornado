<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /**
     * @var \React\Promise\CancellablePromiseInterface
     */
    private $reactPromise;

    /** @var bool */
    private $isHandled;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    public function cancel(): void
    {
        $this->reactPromise->cancel();
    }

    public static function createUnhandled(\React\Promise\CancellablePromiseInterface $reactPromise, FailingPromiseCollection $failingPromiseCollection)
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = false;
        $promiseWrapper->reactPromise = $reactPromise;
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

    public static function createHandled(\React\Promise\CancellablePromiseInterface $reactPromise)
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = true;
        $promiseWrapper->reactPromise = $reactPromise;

        return $promiseWrapper;
    }

    public function getReactPromise(): \React\Promise\CancellablePromiseInterface
    {
        return $this->reactPromise;
    }

    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
