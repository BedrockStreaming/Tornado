<?php

namespace M6Web\Tornado\Adapter\Symfony;

use M6Web\Tornado\Deferred;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface as SfHttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface as SfResponseInterface;

class HttpClient implements \M6Web\Tornado\HttpClient
{
    /** @var SfHttpClientInterface */
    private $symfonyClient;

    /** @var EventLoop */
    private $eventLoop;

    /** @var SfResponseInterface[] */
    private $jobs = [];

    /** @var ResponseFactoryInterface */
    private $responseFactory;

    /** @var StreamFactoryInterface */
    private $streamFactory;

    /** @var int */
    private $lastRequestId = 0;

    public function __construct(SfHttpClientInterface $symfonyClient, EventLoop $eventLoop, ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
    {
        $this->symfonyClient = $symfonyClient;
        $this->eventLoop = $eventLoop;
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    public function sendRequest(RequestInterface $request): Promise
    {
        $body = $request->getBody();
        if ($body->isSeekable()) {
            $body->seek(0);
        }

        $requestId = $this->lastRequestId = ++$this->lastRequestId % PHP_INT_MAX;
        try {
            $this->jobs[$requestId] = $this->symfonyClient->request($request->getMethod(), (string) $request->getUri(), [
                'headers' => $request->getHeaders(),
                'body' => $body->getContents(),
                'http_version' => $request->getProtocolVersion(),
                'user_data' => [
                    $deferred = $this->eventLoop->deferred(),
                    $requestId,
                ],
            ]);
        } catch (\Exception $exception) {
            return $this->eventLoop->promiseRejected($exception);
        }

        // Register the internal event loop only for the first request
        if (count($this->jobs) === 1) {
            $this->eventLoop->async($this->symfonyEventLoop());
        }

        return $deferred->getPromise();
    }

    private function symfonyEventLoop(): \Generator
    {
        do {
            yield $this->eventLoop->idle();

            $currentJobs = $this->jobs;
            $this->jobs = [];
            /**
             * @var SfResponseInterface $response
             * @var ChunkInterface      $chunk
             */
            foreach ($this->symfonyClient->stream($currentJobs, 0) as $response => $chunk) {
                /** @var Deferred $deferred */
                [$deferred, $requestId] = $response->getInfo('user_data');

                try {
                    if ($chunk->isTimeout() || !$chunk->isLast()) {
                        // To prevent the client to throw an exception
                        // https://github.com/symfony/symfony/issues/32673#issuecomment-548327270
                        $response->getStatusCode();
                        $this->jobs[$requestId] = $response;
                        continue;
                    }

                    // the full content of $response just completed
                    // $response->getContent() is now a non-blocking call
                    $deferred->resolve($this->toPsrResponse($response));

                    // Stream loop may yield the same response several times,
                    // then the response may already by in the list of responses to process.
                    // To prevent to resolve it twice, remove it.
                    unset($this->jobs[$requestId]);
                } catch (\Throwable $exception) {
                    $deferred->reject($exception);
                }
            }
        } while ($this->jobs);
    }

    /**
     * Inspired from https://github.com/symfony/http-client/blob/master/Psr18Client.php
     *
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     */
    private function toPsrResponse(SfResponseInterface $response): ResponseInterface
    {
        $psrResponse = $this->responseFactory->createResponse($response->getStatusCode());
        foreach ($response->getHeaders(false) as $name => $values) {
            foreach ($values as $value) {
                $psrResponse = $psrResponse->withAddedHeader($name, $value);
            }
        }

        $body = $this->streamFactory->createStream($response->getContent(false));

        if ($body->isSeekable()) {
            $body->seek(0);
        }

        return $psrResponse->withBody($body);
    }
}

/**
 * @internal
 */
class InternalSymfonyJob
{
    /** @var Deferred */
    public $deferred;
    /** @var SfResponseInterface */
    public $response;

    public function __construct(Deferred $deferred, SfResponseInterface $response)
    {
        $this->deferred = $deferred;
        $this->response = $response;
    }
}
