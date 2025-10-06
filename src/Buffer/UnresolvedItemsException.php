<?php

namespace M6Web\Tornado\Buffer;

class UnresolvedItemsException extends \Exception
{
    public function __construct(
        private readonly array $items
    )
    {
        parent::__construct("Deferred item not resolved");
    }

    public function getItems(): array
    {
        return $this->items;
    }
}