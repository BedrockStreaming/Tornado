<?php

namespace M6WebTest\Tornado\Adapter\Guzzle;

use GuzzleHttp\Promise;
use M6Web\Tornado\Adapter\Guzzle\GuzzleClientWrapper;
use Psr\Http\Message\ResponseInterface;

final class GuzzleMockWrapper implements GuzzleClientWrapper
{
    /** @var \GuzzleHttp\Client */
    private $guzzleClient;

    /** @var int */
    public $ticks;

    /**
     * @param ResponseInterface[]|\Exception[] $queue
     */
    public function __construct(array $queue)
    {
        $this->ticks = 0;

        $mockHandler = new \GuzzleHttp\Handler\MockHandler($queue);
        $stack = \GuzzleHttp\HandlerStack::create($mockHandler);
        $this->guzzleClient = new \GuzzleHttp\Client([
            'handler' => $stack,
        ]);
    }

    public function getClient(): \GuzzleHttp\ClientInterface
    {
        return $this->guzzleClient;
    }

    public function tick(): void
    {
        $this->ticks++;
        Promise\Utils::queue()->run();
    }
}
