<?php

namespace M6Test\Front\Async\Adapter\Guzzle;

use M6\Front\Async\Adapter\Guzzle\GuzzleClientWrapper;
use M6\Front\Async\EventLoop;

class HttpClientTest extends \M6Test\Front\Async\HttpClientTest
{
    protected function createHttpClient(EventLoop $eventLoop, GuzzleClientWrapper $wrapper): \M6\Front\Async\HttpClient
    {
        return new \M6\Front\Async\Adapter\Guzzle\HttpClient($eventLoop, $wrapper);
    }
}
