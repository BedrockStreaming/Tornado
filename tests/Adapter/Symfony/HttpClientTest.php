<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Symfony;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\HttpClient;
use M6WebTest\Tornado\HttpClientTestCase;

class HttpClientTest extends HttpClientTestCase
{
    protected function createHttpClient(EventLoop $eventLoop, array $responsesOrExceptions): HttpClient
    {
        $callback = function ($method, $url, $options) use (&$responsesOrExceptions) {
            $response = array_shift($responsesOrExceptions);
            if ($response === null) {
                throw new \Exception('No more response to return.');
            }

            if ($response instanceof \Exception) {
                throw $response;
            }

            return new \Symfony\Component\HttpClient\Response\MockResponse(
                (string) $response->getBody(),
                [
                    'response_headers' => $response->getHeaders(),
                    'redirect_count' => 0,
                    'redirect_url' => null,
                    'start_time' => microtime(true),
                    'http_method' => $method,
                    'http_code' => $response->getStatusCode(),
                    'error' => null,
                    'user_data' => $options['user_data'],
                    'url' => $url,
                ]
            );
        };

        return new \M6Web\Tornado\Adapter\Symfony\HttpClient(
            new \Symfony\Component\HttpClient\MockHttpClient($callback),
            $eventLoop,
            new \Http\Factory\Guzzle\ResponseFactory(),
            new \Http\Factory\Guzzle\StreamFactory()
        );
    }
}
