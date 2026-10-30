<?php

declare(strict_types=1);

/*
 * Sends requests with one client, then prints the consumed resources as JSON.
 * Usage: php http-client-worker.php <client> <base uri> <requests> <concurrency> <delay ms> <body bytes>
 */

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;

[, $clientName, $baseUri, $nbRequests, $concurrency, $delay, $size] = $argv;
$nbRequests = (int) $nbRequests;
$concurrency = (int) $concurrency;

$responseFactory = new Http\Factory\Guzzle\ResponseFactory();
$streamFactory = new Http\Factory\Guzzle\StreamFactory();
[$eventLoop, $httpClient] = match ($clientName) {
    // Same request timeout as in production.
    'revolt' => [
        $eventLoop = new Adapter\Revolt\EventLoop(),
        new Adapter\Revolt\HttpClient($eventLoop, $responseFactory, $streamFactory, timeout: 60.0),
    ],
    'guzzle' => [
        $eventLoop = new Adapter\Revolt\EventLoop(),
        new Adapter\Guzzle\HttpClient($eventLoop, new Adapter\Guzzle\CurlMultiClientWrapper()),
    ],
    // Production-like setting: curl waits for activity up to 5ms on each tick.
    'guzzle-5ms' => [
        $eventLoop = new Adapter\Revolt\EventLoop(),
        new Adapter\Guzzle\HttpClient($eventLoop, new Adapter\Guzzle\CurlMultiClientWrapper([], ['select_timeout' => 0.005])),
    ],
    'symfony' => [
        $eventLoop = new Adapter\Revolt\EventLoop(),
        new Adapter\Symfony\HttpClient(new Symfony\Component\HttpClient\CurlHttpClient([], $concurrency), $eventLoop, $responseFactory, $streamFactory),
    ],
};

$worker = function (int $nbWorkerRequests) use ($httpClient, $baseUri, $delay, $size): Generator {
    $bytes = 0;
    for ($i = 0; $i < $nbWorkerRequests; $i++) {
        $response = yield $httpClient->sendRequest(new GuzzleHttp\Psr7\Request('GET', "$baseUri/delay/$delay?size=$size"));
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('Unexpected status '.$response->getStatusCode());
        }
        $bytes += $response->getBody()->getSize();
    }

    return $bytes;
};

// Measures how late a periodic timer is fired, to detect clients blocking the event loop.
$maxLoopLag = 0.0;
$lastTick = hrtime(true);
Revolt\EventLoop::unreference(Revolt\EventLoop::repeat(0.01, function () use (&$maxLoopLag, &$lastTick): void {
    $now = hrtime(true);
    $maxLoopLag = max($maxLoopLag, ($now - $lastTick) / 1e9 - 0.01);
    $lastTick = $now;
}));

$memoryBefore = memory_get_usage();
$usageBefore = getrusage();
$start = hrtime(true);

$promises = [];
for ($i = 0; $i < $concurrency; $i++) {
    $promises[] = $eventLoop->async($worker(intdiv($nbRequests, $concurrency) + ($i < $nbRequests % $concurrency ? 1 : 0)));
}
$bytes = array_sum($eventLoop->wait($eventLoop->promiseAll(...$promises)));

$wall = (hrtime(true) - $start) / 1e9;
$usageAfter = getrusage();
$cpuTime = fn (array $usage, string $type) => $usage["ru_$type.tv_sec"] + $usage["ru_$type.tv_usec"] / 1e6;

if ($bytes !== $nbRequests * (int) $size) {
    throw new RuntimeException("Received $bytes bytes instead of ".($nbRequests * (int) $size));
}

echo json_encode([
    'wall' => $wall,
    'user' => $cpuTime($usageAfter, 'utime') - $cpuTime($usageBefore, 'utime'),
    'system' => $cpuTime($usageAfter, 'stime') - $cpuTime($usageBefore, 'stime'),
    'peakPhpMemory' => memory_get_peak_usage() - $memoryBefore,
    'maxRss' => $usageAfter['ru_maxrss'] * 1024,
    'maxLoopLag' => $maxLoopLag,
]), "\n";
