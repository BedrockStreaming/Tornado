<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /** @var SwoolePromise */
    private $swoolePromise;

    /** @var bool */
    private $isHandled;

    private $deferredExecutor;

    /**
     * Use named (static) constructor instead
     */
    private function __construct(?callable $deferredExecutor = null)
    {
        $this->deferredExecutor = $deferredExecutor;
    }

    public static function createUnhandled(SwoolePromise $swoolePromise, FailingPromiseCollection $failingPromiseCollection, ?callable $deferredExecutor = null)
    {
        $promiseWrapper = new self($deferredExecutor);
        $promiseWrapper->isHandled = false;
        $promiseWrapper->swoolePromise = $swoolePromise;
        $promiseWrapper->swoolePromise->then(
            null,
            function (?\Throwable $reason) use ($promiseWrapper, $failingPromiseCollection) {
                if ($reason !== null && !$promiseWrapper->isHandled) {
                    $failingPromiseCollection->watchFailingPromise($promiseWrapper, $reason);
                }
            }
        );

        return $promiseWrapper;
    }

    public static function createHandled(SwoolePromise $swoolePromise, ?callable $deferredExecutor = null)
    {
        $promiseWrapper = new self($deferredExecutor);
        $promiseWrapper->isHandled = true;
        $promiseWrapper->swoolePromise = $swoolePromise;

        return $promiseWrapper;
    }

    public function getSwoolePromise(): SwoolePromise
    {
        if($this->deferredExecutor) {
            return $this->swoolePromise->then($this->deferredExecutor);
        }

        return $this->swoolePromise;
    }

    public static function toHandledPromise(Promise $promise, FailingPromiseCollection $failingPromiseCollection): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        $promise->isHandled = true;
        $failingPromiseCollection->unwatchPromise($promise);

        return $promise;
    }
}
