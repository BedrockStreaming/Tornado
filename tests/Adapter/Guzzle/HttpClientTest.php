<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Guzzle;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\HttpClientTestCase;

class HttpClientTest extends HttpClientTestCase
{
    protected function createHttpClient(EventLoop $eventLoop, array $responsesOrExceptions): \M6Web\Tornado\HttpClient
    {
        return new \M6Web\Tornado\Adapter\Guzzle\HttpClient(
            $eventLoop,
            new GuzzleMockWrapper($responsesOrExceptions)
        );
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testWrapperIsTicked(EventLoop $eventLoop): void
    {
        $httpClient = new \M6Web\Tornado\Adapter\Guzzle\HttpClient(
            $eventLoop,
            $wrapper = new GuzzleMockWrapper([new Response(200, [], 'Example Domain')])
        );
        $request = new Request('GET', 'http://www.example.com');

        $eventLoop->wait($httpClient->sendRequest($request));
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testRequestExceptionsAreSuccessful(EventLoop $eventLoop): void
    {
        $request = new Request('GET', 'http://www.example.com');
        $expectedResponse = new Response(500, [], 'An error occurred');
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [
                new RequestException(
                    'This is an exception',
                    $request,
                    $expectedResponse
                ),
            ]
        );

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertSame($expectedResponse, $response);
    }
}
