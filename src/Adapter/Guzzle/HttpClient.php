<?php

namespace M6\Front\Async\Adapter\Guzzle;

use GuzzleHttp\Exception\RequestException;
use M6\Front\Async\EventLoop;
use M6\Front\Async\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class HttpClient implements \M6\Front\Async\HttpClient
{
    /**
     * @var EventLoop
     */
    private $eventLoop;

    /**
     * @var GuzzleClientWrapper
     */
    private $clientWrapper;

    private $nbConcurrentRequests = 0;

    public function __construct(EventLoop $eventLoop, GuzzleClientWrapper $clientWrapper)
    {
        $this->eventLoop = $eventLoop;
        $this->clientWrapper = $clientWrapper;
    }

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): Promise
    {
        $deferred = $this->eventLoop->deferred();

        $this->clientWrapper->getClient()->sendAsync($request)->then(
            function (ResponseInterface $response) use ($deferred) {
                $deferred->resolve($response);
                $this->nbConcurrentRequests--;
            },
            function (RequestException $exception) use ($deferred) {
                if ($exception->getResponse()) {
                    $deferred->resolve($exception->getResponse());
                } else {
                    $deferred->reject($exception);
                }
                $this->nbConcurrentRequests--;
            }
        );

        if ($this->nbConcurrentRequests++ === 0) {
            $this->eventLoop->async($this->guzzleEventLoop());
        }

        return $deferred->getPromise();
    }

    private function guzzleEventLoop(): \Generator
    {
        do {
            yield $this->eventLoop->idle();
            $this->clientWrapper->tick();
        } while ($this->nbConcurrentRequests);
    }
}
