#!/usr/bin/env php
<?php

namespace M6WebExamples\Tornado;

use Swoole\Coroutine;
use Swoole\Event;
use function Swoole\Coroutine\batch;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\WaitGroup;

require __DIR__.'/../vendor/autoload.php';

$cids = [];

$finish = function() use (&$cids){
    foreach ($cids as $cid) {
        unset($cids[$cid]);
        Coroutine::resume($cid);
    }
};

$outputBuffer = '';
$createIdleGenerator = function (string $id, int $count) use (&$outputBuffer, &$cids, $finish) {
    Coroutine::create(function() use ($id, &$outputBuffer, &$cids, &$count, $finish) {
        while ($count--) {
            $cid = Coroutine::getCid();
            $cids[$cid] = $cid;
            //print_r("yield ". $id . "\n");
            Coroutine::yield();
            $outputBuffer .= $id;
            print_r($outputBuffer . "\n");
        }

        $finish();
    });
};

$createIdleGenerator('A', 3);
$createIdleGenerator('B', 2);
$createIdleGenerator('C', 1);

$finish();