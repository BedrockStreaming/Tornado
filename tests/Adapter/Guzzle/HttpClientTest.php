<?php

namespace M6WebTest\Tornado\Adapter\Guzzle;

use M6Web\Tornado\Adapter\Guzzle\GuzzleClientWrapper;
use M6Web\Tornado\EventLoop;

class HttpClientTest extends \M6WebTest\Tornado\HttpClientTest
{
    protected function createHttpClient(EventLoop $eventLoop, GuzzleClientWrapper $wrapper): \M6Web\Tornado\HttpClient
    {
        return new \M6Web\Tornado\Adapter\Guzzle\HttpClient($eventLoop, $wrapper);
    }
}
