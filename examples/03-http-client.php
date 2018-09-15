#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\HttpClient;

function monitorRequest(EventLoop $eventLoop, HttpClient $httpClient, string $uri): \Generator
{
    // Let's use Guzzle Psr7 implementation
    $request = new \GuzzleHttp\Psr7\Request('GET', $uri);

    $start = microtime(true);
    /** @var \Psr\Http\Message\ResponseInterface */
    $response = yield $httpClient->sendRequest($request);
    $duration = microtime(true) - $start;

    return "[{$response->getStatusCode()}]\t $uri\t\t $duration";
}

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

// Tornado provides only one HttpClient implementation, using Guzzle
$httpClient = new Adapter\Guzzle\HttpClient($eventLoop, new Adapter\Guzzle\CurlMultiClientWrapper());

// Let's call several endpoints… concurrently!
echo "Let's start!\n";
echo "Requests in progress…\n";
$start = microtime(true);
$results = $eventLoop->wait(
    $eventLoop->promiseAll(
        $eventLoop->async(monitorRequest($eventLoop, $httpClient, 'http://httpbin.org/status/404')),
        $eventLoop->async(monitorRequest($eventLoop, $httpClient, 'http://www.google.com')),
        $eventLoop->async(monitorRequest($eventLoop, $httpClient, 'http://www.example.com'))
    )
);
$duration = microtime(true) - $start;

echo "Global duration: $duration\n";
echo implode(PHP_EOL, $results).PHP_EOL;
echo "Finished!\n";
