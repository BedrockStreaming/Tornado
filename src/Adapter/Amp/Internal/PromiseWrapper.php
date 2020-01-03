<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\CancellationException;
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
     * @var callable
     */
    private $cancellation;

    /** @var bool */
    private $isHandled;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function cancel(CancellationException $exception): void
    {
        ($this->cancellation)($exception);
    }

    public static function createUnhandled(\Amp\Promise $ampPromise, FailingPromiseCollection $failingPromiseCollection, callable $cancellation)
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = false;
        $promiseWrapper->ampPromise = $ampPromise;
        $promiseWrapper->cancellation = $cancellation;
        $promiseWrapper->ampPromise->onResolve(
            function (?\Throwable $reason, $value) use ($promiseWrapper, $failingPromiseCollection) {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
                $promiseWrapper->cancellation = function () {};
            }
        );

        return $promiseWrapper;
    }

    public static function createHandled(\Amp\Promise $ampPromise, callable $cancellation)
    {
        $promiseWrapper = new self();
        $promiseWrapper->cancellation = $cancellation;
        $promiseWrapper->isHandled = true;
        $promiseWrapper->ampPromise = $ampPromise;

        return $promiseWrapper;
    }

    public function getAmpPromise(): \Amp\Promise
    {
        return $this->ampPromise;
    }

    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
