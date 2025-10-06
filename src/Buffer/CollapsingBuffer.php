<?php

namespace M6Web\Tornado\Buffer;

use M6Web\Tornado\Promise;

class CollapsingBuffer
{
    private array $knownPromises = [];

    public function __construct(
        private readonly AsyncBuffer $buffer
    )
    {
    }

    public function registerWithKey(string $key, mixed ...$args): Promise
    {
        if (array_key_exists($key, $this->knownPromises)) {
            return $this->knownPromises[$key];
        }

        $promise = $this->buffer->register(...$args);
        $this->knownPromises[$key] = $promise;

        return $promise;
    }
}