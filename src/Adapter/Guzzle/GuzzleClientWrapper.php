<?php

declare(strict_types=1);

namespace M6Web\Tornado\Adapter\Guzzle;

interface GuzzleClientWrapper
{
    public function getClient(): \GuzzleHttp\ClientInterface;

    public function tick(): void;
}
