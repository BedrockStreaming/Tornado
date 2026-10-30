<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Revolt;

use GuzzleHttp\Psr7\Request;
use Http\Factory\Guzzle\ResponseFactory;
use Http\Factory\Guzzle\StreamFactory;
use M6Web\Tornado\Adapter\Revolt\EventLoop;
use M6Web\Tornado\Adapter\Revolt\HttpClient;
use PHPUnit\Framework\TestCase;

class HttpClientTest extends TestCase
{
    /** @var resource */
    private static $serverProcess;
    private static string $baseUri;

    private EventLoop $eventLoop;
    private HttpClient $httpClient;

    public static function setUpBeforeClass(): void
    {
        $port = (int) substr(self::unusedAddress(), strlen('127.0.0.1:'));

        $process = proc_open(
            [PHP_BINARY, __DIR__.'/../../Fixtures/http-server.php', (string) $port],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        self::assertSame("listening\n", fgets($pipes[1]));
        self::$serverProcess = $process;
        self::$baseUri = "http://127.0.0.1:$port";
    }

    public static function tearDownAfterClass(): void
    {
        proc_terminate(self::$serverProcess);
        proc_close(self::$serverProcess);
    }

    protected function setUp(): void
    {
        $this->eventLoop = new EventLoop();
        $this->httpClient = new HttpClient($this->eventLoop, new ResponseFactory(), new StreamFactory(), 2.0);
    }

    public function testGet(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/echo?a=b')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['a', 'b'], $response->getHeader('X-Multi'));
        $echo = json_decode((string) $response->getBody(), true);
        $this->assertSame('GET', $echo['method']);
        $this->assertSame('/echo?a=b', $echo['target']);
        $this->assertSame([substr(self::$baseUri, strlen('http://'))], $echo['headers']['host']);
        $this->assertArrayNotHasKey('content-length', $echo['headers']);
    }

    public function testPostBody(): void
    {
        $request = new Request('POST', self::$baseUri.'/echo', ['Content-Type' => 'text/plain', 'Content-Length' => '1'], 'Hello body');

        $response = $this->eventLoop->wait($this->httpClient->sendRequest($request));

        $echo = json_decode((string) $response->getBody(), true);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('Hello body', $echo['body']);
        $this->assertSame(['10'], $echo['headers']['content-length']);
    }

    public function testErrorStatusesAreResolved(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/status/500')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Status 500', (string) $response->getBody());
    }

    public function testHeadHasNoBody(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('HEAD', self::$baseUri.'/status/200')));
        $this->assertSame('', (string) $response->getBody());

        // The connection must still be usable.
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/status/201')));
        $this->assertSame(201, $response->getStatusCode());
    }

    public function testChunkedBody(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/chunked')));

        $this->assertSame('Hello, World', (string) $response->getBody());
    }

    public function testBodyDelimitedByConnectionClose(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/close')));
        $this->assertSame('Until the end', (string) $response->getBody());

        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/close')));
        $this->assertSame('Until the end', (string) $response->getBody());
    }

    public function testRetryWhenIdleConnectionWasClosedByServer(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/drop')));
        $this->assertSame('Dropped', (string) $response->getBody());

        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/status/202')));
        $this->assertSame(202, $response->getStatusCode());
    }

    public function testLargeBody(): void
    {
        $response = $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', self::$baseUri.'/delay/0?size=5000000')));

        $this->assertSame(5000000, $response->getBody()->getSize());
    }

    public function testConcurrentRequests(): void
    {
        $getBodySize = function (int $delay, int $size): \Generator {
            $response = yield $this->httpClient->sendRequest(new Request('GET', self::$baseUri."/delay/$delay?size=$size"));

            return $response->getBody()->getSize();
        };

        $start = microtime(true);
        $sizes = $this->eventLoop->wait($this->eventLoop->promiseAll(
            ...array_map(fn ($i) => $this->eventLoop->async($getBodySize(200, $i)), range(1, 20))
        ));

        $this->assertSame(range(1, 20), $sizes);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    public function testConnectionRefused(): void
    {
        $address = self::unusedAddress();

        $this->expectException(\RuntimeException::class);
        $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', "http://$address/")));
    }

    public function testInvalidUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->eventLoop->wait($this->httpClient->sendRequest(new Request('GET', 'this is not a valid url')));
    }

    public function testTimeout(): void
    {
        $httpClient = new HttpClient($this->eventLoop, new ResponseFactory(), new StreamFactory(), 0.1);

        $this->expectExceptionMessage('No activity on the connection');
        $this->eventLoop->wait($httpClient->sendRequest(new Request('GET', self::$baseUri.'/delay/1000')));
    }

    private static function unusedAddress(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($server);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return $address;
    }

    protected function tearDown(): void
    {
        foreach (\Revolt\EventLoop::getIdentifiers() as $callbackId) {
            \Revolt\EventLoop::cancel($callbackId);
        }
    }
}
