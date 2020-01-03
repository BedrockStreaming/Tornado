<?php

namespace M6WebTest\Tornado;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use M6Web\Tornado\CancellationException;
use M6Web\Tornado\EventLoop;
use M6Web\Tornado\HttpClient;
use PHPUnit\Framework\TestCase;

abstract class HttpClientTest extends TestCase
{
    /**
     * @param array $responsesOrExceptions Psr7\Response to return, or \Exception to throw
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
            [new Response(200, [], 'This is a test')]
        );
        $request = new Request('GET', 'http://www.example.com');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('This is a test', (string) $response->getBody());
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
    public function testGetServerErrorUrl(EventLoop $eventLoop)
    {
        $httpClient = $this->createHttpClient(
            $eventLoop,
            [new Response(500, [], 'Error')]
        );

        $request = new Request('GET', 'http://www.example.com/500');

        $response = $eventLoop->wait($httpClient->sendRequest($request));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertContains('Error', (string) $response->getBody());
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

    /**
     * @dataProvider eventLoopProvider
     */
    public function testHttpRequestsCancellation(EventLoop $eventLoop)
    {
        $delayCancellation = 20;
        $delayRequestExecution = 200;
        $msgCancellation = 'canceller resolved';
        $msgResolvedRequest = 'Simple response should be reachable only on schyronous mode';

        $httpClient = $this->createHttpClient($eventLoop, [new Response(200, [], $msgResolvedRequest)]);

        $getResponseWithDelay = function (string $url, int $delayRequestExecution) use ($httpClient, $eventLoop): \Generator {
            yield $eventLoop->delay($delayRequestExecution);

            return (string) (yield $httpClient->sendRequest(new Request('GET', $url)))->getBody();
        };

        $response = null;
        try {
            $response = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->async($getResponseWithDelay('http://www.example.com', $delayRequestExecution)),
                    $eventLoop->async($this->canceller($eventLoop, $delayCancellation, $promise))
                )
            );
        } catch (CancellationException $e) {
            $response = $msgCancellation;
        } catch (\Throwable $e) {
            $response = 'other Exception: '.$e->getMessage();
        }

        $actual = $msgCancellation;
        $description = $this->dataDescription();
        if ($description === 'Tornado (synchronous)') {
            $actual = [$msgResolvedRequest, $actual];
        }

        $this->assertEquals($response, $actual);
    }

    private function canceller(EventLoop $eventLoop, int $time, \M6Web\Tornado\Promise &$promise)
    {
        yield $eventLoop->delay($time);
        $promise->cancel();

        return 'canceller resolved';
    }
}
