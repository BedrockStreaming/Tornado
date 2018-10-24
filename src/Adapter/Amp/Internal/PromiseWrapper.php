<?php

namespace M6Web\Tornado\Adapter\Amp\Internal;

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

    public function __construct(\Amp\Promise $ampPromise)
    {
        $this->ampPromise = $ampPromise;
    }

    public function getAmpPromise(): \Amp\Promise
    {
        return $this->ampPromise;
    }

    public static function downcast(Promise $promise): self
    {
        assert($promise instanceof self, new \Error('Input promise was not created by this adapter.'));

        return $promise;
    }

    public static function fromGenerator(\Generator $generator): self
    {
        $promise = $generator->current();
        if (!$promise instanceof self) {
            throw new \Error('Asynchronous function is yielding a ['.gettype($promise).'] instead of a Promise.');
        }

        return self::downcast($promise);
    }

    /**
     * @param Promise[] ...$promises
     *
     * @return \Amp\Promise[]
     */
    public static function toAmpPromiseArray(Promise ...$promises): array
    {
        return array_map(function (Promise $promise) {
            return self::downcast($promise)->ampPromise;
        }, $promises);
    }
}
