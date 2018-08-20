<?php

namespace M6Test\Front\Async\Adapter\Guzzle;

use M6Web\Tornado\Adapter\Guzzle\GuzzleClientWrapper;
use M6Web\Tornado\EventLoop;

class HttpClientTest extends \M6Test\Front\Async\HttpClientTest
{
    protected function createHttpClient(EventLoop $eventLoop, GuzzleClientWrapper $wrapper): \M6Web\Tornado\HttpClient
    {
        return new \M6Web\Tornado\Adapter\Guzzle\HttpClient($eventLoop, $wrapper);
    }
}
