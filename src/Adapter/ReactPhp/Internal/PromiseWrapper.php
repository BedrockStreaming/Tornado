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

    public static function fromPromise(Promise $promise): self
    {
        assert($promise instanceof self);

        return $promise;
    }

    public function getReactPromise(): \React\Promise\PromiseInterface
    {
        return $this->reactPromise;
    }
}
