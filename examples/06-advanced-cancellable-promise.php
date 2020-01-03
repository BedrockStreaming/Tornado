#!/usr/bin/env php
<?php

require __DIR__.'/../vendor/autoload.php';

use M6Web\Tornado\Adapter;
use M6Web\Tornado\EventLoop;

// Choose your adapter.
$eventLoop = new Adapter\Tornado\EventLoop();
//$eventLoop = new Adapter\Tornado\SynchronousEventLoop();
//$eventLoop = new Adapter\Amp\EventLoop();
//$eventLoop = new Adapter\ReactPhp\EventLoop(new React\EventLoop\StreamSelectLoop());

function timer(EventLoop $eventLoop, string $id, int $time, \M6Web\Tornado\Promise &$promise = null)
{
    $result = 'not resolved';
    try {
        echo "[$id] Starting to wait $time …\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working\n";
        yield $eventLoop->delay($time);

        if ($promise) {
            echo "[$id] Cancelling other promise …\n";
            $promise->cancel();
            echo "[$id] Cancellation Done!\n";
        }

        echo "[$id] Working after cancellation !\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working after cancellation !\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working after cancellation !\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working after cancellation !\n";
        yield $eventLoop->delay($time);
        echo "[$id] Working after cancellation !\n";
        yield $eventLoop->delay($time);

        $result = $id;
    } catch (\Exception $e) {
        echo "[$id] Cancelled … : clean workspace and other stuff\n";
    }

    return $result;
}

echo "Let's start!\n";

try {
    $result = $eventLoop->wait(
        $eventLoop->promiseAll(
            $promise = $eventLoop->promiseAll(
                $eventLoop->async(timer($eventLoop, ' Timer A ', 100)),
                $eventLoop->async(timer($eventLoop, ' Timer B ', 100))
            ),
            $eventLoop->async(timer($eventLoop, 'Canceller', 100, $promise))
        )
    );
} catch (\M6Web\Tornado\CancellationException $e) {
    $result = 'cancelled promise';
} catch (\Exception $e) {
    $result = 'other exception';
}

echo "async cancellation with result conservation:\n";

var_dump($result);

echo "Finished!\n";
