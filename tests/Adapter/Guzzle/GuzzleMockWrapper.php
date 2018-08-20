<?php

namespace M6Test\Front\Async\Adapter\Guzzle;

use M6\Front\Async\Adapter\Guzzle\GuzzleClientWrapper;
use GuzzleHttp\Promise;

final class GuzzleMockWrapper implements GuzzleClientWrapper
{
    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzleClient;

    /**
     * @var array
     */
    private $transactions = [];

    /**
     * @var int
     */
    public $ticks;

    public function __construct(array $queue)
    {
        $this->ticks = 0;

        $mockHandler = new \GuzzleHttp\Handler\MockHandler($queue);
        $stack = \GuzzleHttp\HandlerStack::create($mockHandler);
        $history = \GuzzleHttp\Middleware::history($this->transactions);
        $stack->push($history);
        $this->guzzleClient = new \GuzzleHttp\Client([
            'handler' => $stack,
        ]);
    }

    public function getClient(): \GuzzleHttp\ClientInterface
    {
        return $this->guzzleClient;
    }

    public function getTransactions(): array
    {
        return $this->transactions;
    }

    public function tick(): void
    {
        $this->ticks++;
        Promise\queue()->run();
    }
}
