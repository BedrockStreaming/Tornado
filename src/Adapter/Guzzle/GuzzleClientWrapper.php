<?php

namespace M6\Front\Async\Adapter\Guzzle;

interface GuzzleClientWrapper
{
    public function getClient(): \GuzzleHttp\ClientInterface;

    public function tick(): void;
}
