<?php

namespace M6WebTest\Tornado;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\HttpClient;
use PHPUnit\Framework\TestCase;

abstract class HttpClientTest extends TestCase
{
    /**
     * @param EventLoop $eventLoop
     * @param array     $responsesOrExceptions Psr7\Response to return, or \Exception to throw
     *
     * @return HttpClient
     */
    abstract protected function createHttpClient(EventLoop $eventLoop, array $responsesOrExceptions): HttpClient;

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
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [new Response(200, [], 'Example Domain')]
        );
        $request = new Request('GET', 'http://www.example.com');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testGetNotFoundUrl(EventLoop $eventLoop)
    {
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [new Response(404)]
        );

        $request = new Request('GET', 'http://www.example.com/404');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testInvalidUrl(EventLoop $eventLoop)
    {
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [new \Exception('Error Communicating with Server')]
        );

        $request = new Request('GET', 'this is not a valid url');
        $promise = $httpClient->sendRequest($request);

        $this->expectException(\Exception::class);
        $eventLoop->wait($promise);
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testSynchronousRequests(EventLoop $eventLoop)
    {
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [
                new Response(200, [], 'Example Domain'),
                new Response(200, [], 'Example Domain'),
            ]
        );

        $request = new Request('GET', 'http://www.example.com');

        $response = $eventLoop->wait($httpClient->sendRequest($request));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());

        $response = $eventLoop->wait($httpClient->sendRequest($request));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('Example Domain', (string) $response->getBody());
    }

    /**
     * @dataProvider eventLoopProvider
     */
    public function testAsynchronousRequests(EventLoop $eventLoop)
    {
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [
                new Response(404),
                new Response(200, [], 'Example Domain'),
                new Response(200, [], 'Example Domain'),
                new Response(404),
            ]
        );

        $getStatusCode = function (string $url) use ($httpClient): \Generator {
            $request = new Request('GET', $url);
            $response = yield $httpClient->sendRequest($request);

            return $response->getStatusCode();
        };

        $this->assertSame(
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
    }
}
