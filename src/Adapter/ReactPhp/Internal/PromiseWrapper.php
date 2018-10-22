<?php

namespace M6Web\Tornado\Adapter\ReactPhp\Internal;

use M6Web\Tornado\Promise;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
class PromiseWrapper implements Promise
{
    /**
     * @var \React\Promise\PromiseInterface
     */
    private $reactPromise;

    public function __construct(\React\Promise\PromiseInterface $reactPromise)
    {
        $this->reactPromise = $reactPromise;
    }

    public function getReactPromise(): \React\Promise\PromiseInterface
    {
        return $this->reactPromise;
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
     * @return \React\Promise\PromiseInterface[]
     */
    public static function toReactPromiseArray(Promise ...$promises): array
    {
        return array_map(function (Promise $promise) {
            return self::downcast($promise)->reactPromise;
        }, $promises);
    }
}
