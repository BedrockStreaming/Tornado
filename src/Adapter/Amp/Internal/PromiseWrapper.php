<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    private \Amp\Future $ampPromise;

    private bool $isHandled;

    /**
     * Use named (static) constructor instead
     */
    private function __construct()
    {
    }

    public static function createUnhandled(\Amp\Future $ampPromise, FailingPromiseCollection $failingPromiseCollection): self
    {
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = false;
        $promiseWrapper->ampPromise = $ampPromise;
        $promiseWrapper->ampPromise->catch(
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection) {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    public static function createHandled(\Amp\Future $ampPromise): self
    {
        $ampPromise->ignore();
        $promiseWrapper = new self();
        $promiseWrapper->isHandled = true;
        $promiseWrapper->ampPromise = $ampPromise;

        return $promiseWrapper;
    }

    public function getAmpFuture(): \Amp\Future
    {
        return $this->ampPromise;
    }

    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $promise->getAmpFuture()->ignore();
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
