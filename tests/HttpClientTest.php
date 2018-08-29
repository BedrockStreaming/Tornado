<?php

namespace M6WebTest\Tornado;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use M6Web\Tornado\Adapter\Guzzle\GuzzleClientWrapper;
use M6Web\Tornado\HttpClient;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\Adapter\Guzzle\GuzzleMockWrapper;
use PHPUnit\Framework\TestCase;

abstract class HttpClientTest extends TestCase
{
    abstract protected function createHttpClient(EventLoop $eventLoop, GuzzleClientWrapper $wrapper): HttpClient;

    public function eventLoopProvider()
    {
        yield 'Tornado (synchronous)' => [new \M6Web\Tornado\Adapter\Tornado\SynchronousEventLoop()];
        yield 'Tornado' => [new \M6Web\Tornado\Adapter\Tornado\EventLoop()];
        yield 'ReactPhp' => [new \M6Web\Tornado\Adapter\ReactPhp\EventLoop(new \React\EventLoop\StreamSelectLoop())];
        yield 'AMP' => [new \M6Web\Tornado\Adapter\Amp\EventLoop()];
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testGetValidUrl(EventLoop $eventLoop)
    {
        $wrapper = new GuzzleMockWrapper([
            new Response(200, [], 'Example Domain'),
        ]);
        $httpClient = $this->createHttpClient($eventLoop, $wrapper);
        $request = new Request('GET', 'http://www.example.com');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testGetNotFoundUrl(EventLoop $eventLoop)
    {
        $wrapper = new GuzzleMockWrapper([
            new Response(404),
        ]);
        $httpClient = $this->createHttpClient($eventLoop, $wrapper);

        $request = new Request('GET', 'http://www.example.com/404');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testInvalidUrl(EventLoop $eventLoop)
    {
        $wrapper = new GuzzleMockWrapper([
            new RequestException('Error Communicating with Server', new Request('GET', 'this is not a valid url')),
        ]);
        $httpClient = $this->createHttpClient($eventLoop, $wrapper);

        $request = new Request('GET', 'this is not a valid url');
        $promise = $httpClient->sendRequest($request);

        $this->expectException(\Exception::class);
        $eventLoop->wait($promise);
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testSynchronousRequests(EventLoop $eventLoop)
    {
        $wrapper = new GuzzleMockWrapper([
            new Response(200, [], 'Example Domain'),
            new Response(200, [], 'Example Domain'),
        ]);
        $httpClient = $this->createHttpClient($eventLoop, $wrapper);

        $request = new Request('GET', 'http://www.example.com');

        $response = $eventLoop->wait($httpClient->sendRequest($request));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());

        $response = $eventLoop->wait($httpClient->sendRequest($request));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testAsynchronousRequests(EventLoop $eventLoop)
    {
        $wrapper = new GuzzleMockWrapper([
            new Response(404),
            new Response(200, [], 'Example Domain'),
            new Response(200, [], 'Example Domain'),
            new Response(404),
        ]);
        $httpClient = $this->createHttpClient($eventLoop, $wrapper);

        $getStatusCode = function (string $url) use ($httpClient): \Generator {
            $request = new Request('GET', $url);
            $response = yield $httpClient->sendRequest($request);

            return $response->getStatusCode();
        };

        $this->assertEquals(
            [404, 200, 200, 404],
            $eventLoop->wait(
                $eventLoop->promiseAll(
                    $eventLoop->async($getStatusCode('http://www.example.com/404')),
                    $eventLoop->async($getStatusCode('http://www.example.com')),
                    $eventLoop->async($getStatusCode('http://www.example.com')),
                    $eventLoop->async($getStatusCode('http://www.example.com/404'))
                )
            )
        );
        $this->assertGreaterThanOrEqual(1, $wrapper->ticks);
    }
}
