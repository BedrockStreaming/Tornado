<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\EventLoop;

trait PromiseForeachTest
{
    abstract protected function createEventLoop(): EventLoop;

    /**
     * Helper function to test that promiseForeach works for array AND iterator inputs.
     */
    private function assertSameForeach(EventLoop $eventLoop, array $input, callable $callback, array $expected)
    {
        $this->assertSame(
            $expected,
            $eventLoop->wait($eventLoop->promiseForeach($input, $callback))
        );

        $this->assertSame(
            $expected,
            $eventLoop->wait($eventLoop->promiseForeach(new \ArrayIterator($input), $callback))
        );
    }

    public function testPromiseForeachAcceptsEmptyTraversable()
    {
        $eventLoop = $this->createEventLoop();
        $callback = function () {
            throw new \Exception("Shouldn't be reached!");
        };

        $this->assertSameForeach($eventLoop, [], $callback, []);
    }

    public function testPromiseForeachShouldThrowIfCallbackDoesNotReturnGenerator()
    {
        $eventLoop = $this->createEventLoop();
        $callback = function () {
            return;
        };

        $this->expectException(\TypeError::class);
        $eventLoop->wait(
            $eventLoop->promiseForeach([1], $callback)
        );
    }

    public function testPromiseForeachWithCallbackUsingValueOnly()
    {
        $eventLoop = $this->createEventLoop();
        $callback = function ($value) use ($eventLoop) {
            yield $eventLoop->idle();

            return $value;
        };
        $input = range(1, 10);

        $this->assertSameForeach($eventLoop, $input, $callback, $input);
    }

    public function testPromiseForeachWithCallbackUsingValueAndKey()
    {
        $eventLoop = $this->createEventLoop();
        $callback = function ($value, $key) use ($eventLoop) {
            yield $eventLoop->idle();

            return [$key, $value];
        };
        $input = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4];

        $this->assertSameForeach($eventLoop, $input, $callback, array_map(
            null, array_keys($input), $input
        ));
    }

    public function testPromiseForeachPropagateThrownExceptions()
    {
        $eventLoop = $this->createEventLoop();
        $exception = new \LogicException();
        $callback = function ($value) use ($eventLoop, $exception) {
            yield $eventLoop->idle();
            throw $exception;
        };

        $this->expectExceptionObject($exception);
        $eventLoop->wait(
            $eventLoop->promiseForeach([1], $callback)
        );
    }

    public function testPromiseForeachCatchableException()
    {
        $eventLoop = $this->createEventLoop();
        $createGenerator = function () use ($eventLoop): \Generator {
            try {
                yield $eventLoop->promiseForeach([1], function ($value) use ($eventLoop) {
                    yield $eventLoop->idle();
                    throw new \Exception('This is a failure');
                });
            } catch (\Exception $e) {
                return 'catched';
            }
        };

        $this->assertSame(
            'catched',
            $eventLoop->wait($eventLoop->async($createGenerator()))
        );
    }
}
