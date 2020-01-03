<?php

namespace M6WebTest\Tornado\EventLoopTest;

use M6Web\Tornado\CancellationException;
use M6Web\Tornado\EventLoop;

trait CancellationTest
{
    abstract protected function createEventLoop(): EventLoop;

    public function testIdleThrowACancelledException()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->idle();
        $promise->cancel();

        $this->expectException(CancellationException::class);
        $eventLoop->wait($promise);
    }

    public function testDelayPromiseThrowException()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->delay(self::LONG_WAITING_TIME);
        $promise->cancel();

        $this->expectException(CancellationException::class);
        $eventLoop->wait($promise);
    }

    public function testCannotCancelFulfilledPromise()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->promiseFulfilled(42);
        $promise->cancel();

        $this->assertSame(42, $eventLoop->wait($promise));
    }

    public function testCannotCancelRejectedPromise()
    {
        $eventLoop = $this->createEventLoop();
        $expectedException = new class() extends \Exception {
        };

        $promise = $eventLoop->promiseRejected($expectedException);
        $promise->cancel();

        $this->expectException(get_class($expectedException));
        $eventLoop->wait($promise);
    }

    public function testCancellationDoesNotInterruptExecution()
    {
        $eventLoop = $this->createEventLoop();
        $promise = $eventLoop->idle();

        $result = $eventLoop->wait($eventLoop->async((function () use ($promise) {
            yield $promise;
            $promise->cancel();

            return 'success';
        })()));
        $this->assertSame('success', $result);
    }

    public function testComplexAsync()
    {
        $eventLoop = $this->createEventLoop();
        $promise1 = $eventLoop->idle();
        $promise2 = $eventLoop->async((function () use ($eventLoop) {
            try {
                yield $eventLoop->idle();
            } catch (CancellationException $exception) {
                return;
            }

            throw new \Exception('Nooooooo');
        })());

        $asyncPromise = $eventLoop->async((function () use ($promise1, $promise2) {
            try {
                yield $promise1;
                yield $promise2;
            } catch (CancellationException $exception) {
                $promise1->cancel();
                $promise2->cancel();

                throw $exception;
            }

            return 'success';
        })());

        try {
            $asyncPromise->cancel();
        } catch (CancellationException $exception) {
        }

        $exception = null;
        try {
            $eventLoop->wait($asyncPromise);
        } catch (CancellationException $exception) {
        }
        $this->assertNotNull($exception);

        $exception = null;
        try {
            $eventLoop->wait($promise1);
        } catch (CancellationException  $exception) {
        }
        $this->assertNotNull($exception);

        $this->assertNull($eventLoop->wait(
            $eventLoop->idle()
        ));
    }

    private function canceller(EventLoop $eventLoop, int $time, \M6Web\Tornado\Promise &$promise = null)
    {
        yield $eventLoop->delay($time);

        if ($promise) {
            $promise->cancel();
        }
        yield $eventLoop->delay($time);

        return 'canceller resolved';
    }

    private function timer(EventLoop $eventLoop, string $id, int $time)
    {
        $result = 'not resolved';

        yield $eventLoop->delay($time);

        $result = $id;

        return $result;
    }

    private function stepHaveToBeCancelled(EventLoop $eventLoop, int $time)
    {
        yield $eventLoop->delay($time);

        throw new \LogicException('should not be reach');
    }

    public function testDelayCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->delay(self::LONG_WAITING_TIME),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        }

        $this->assertEquals($result, 'request cancelled');
    }

    public function testAsyncCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->async($this->timer($eventLoop, 'A', self::LONG_WAITING_TIME)),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = 'other Exception';
        }

        $this->assertEquals($result, 'request cancelled');
    }

    public function testPromiseAllCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->promiseAll(
                        $eventLoop->async($this->timer($eventLoop, 'Timer A', self::LONG_WAITING_TIME)),
                        $eventLoop->async($this->timer($eventLoop, 'Timer B', self::LONG_WAITING_TIME))
                    ),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = 'other Exception';
        }

        $this->assertEquals($result, 'request cancelled');
    }

    /** try to cancel a promiseAll */
    public function testPromiseRaceCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $eventLoop->promiseAll(
                    $promise = $eventLoop->promiseRace(
                        $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME)),
                        $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME))
                    ),
                    $eventLoop->async($this->canceller($eventLoop, $shortWaitingTime, $promise))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = $e->getMessage();
        }

        $this->assertEquals($result, 'request cancelled');
    }

    /** try auto cancel a after first resolution */
    public function testPromiseRaceAutoCancellation()
    {
        $eventLoop = $this->createEventLoop();
        $shortWaitingTime = 50;

        try {
            $result = $eventLoop->wait(
                $promise = $eventLoop->promiseRace(
                    $eventLoop->async($this->timer($eventLoop, 'Timer First', $shortWaitingTime)),
                    $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME)),
                    $eventLoop->async($this->stepHaveToBeCancelled($eventLoop, self::LONG_WAITING_TIME))
                )
            );
        } catch (CancellationException $e) {
            $result = 'request cancelled';
        } catch (\Throwable $e) {
            $result = 'other Exception';
        }

        $this->assertEquals($result, 'Timer First');
    }
}
