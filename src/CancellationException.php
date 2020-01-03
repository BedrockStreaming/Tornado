<?php

namespace M6Web\Tornado;

class CancellationException extends \Exception
{
    public function __construct(string $message = "", int $code = 0, CancellationException $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
