<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Amp;

use M6Web\Tornado\Adapter\Amp;
use M6Web\Tornado\EventLoop;
use M6WebTest\Tornado\EventLoopTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EventLoopTest extends EventLoopTestCase
{
    protected function createEventLoop(): EventLoop
    {
        return new Amp\EventLoop();
    }

    public function testStreamShouldReadFromWritable(string $expectedSequence = ''): void
    {
        // Because Amp resolve promises in a slightly different order.
        parent::testStreamShouldReadFromWritable('W0R0W12345R12R34W6R56R');
    }

    #[DataProvider('knownRejectionPositions')]
    public function testPromiseAllShouldHandlePendingRejectionAfterCaughtKnownRejection(bool $knownRejectionFirst): void
    {
        $eventLoop = $this->createEventLoop();
        $pending = $eventLoop->deferred();
        $pendingPromise = $eventLoop->async(
            (fn (): \Generator => yield $pending->getPromise())()
        );
        $expectedException = new \RuntimeException('Known rejection');
        $rejected = $eventLoop->promiseRejected($expectedException);
        $promise = $knownRejectionFirst
            ? $eventLoop->promiseAll($rejected, $pendingPromise)
            : $eventLoop->promiseAll($pendingPromise, $rejected);

        $generator = (function () use ($eventLoop, $pending, $promise): \Generator {
            $caughtException = null;

            try {
                yield $promise;
            } catch (\RuntimeException $exception) {
                $caughtException = $exception;
            }

            $pending->reject(new \RuntimeException('Later rejection'));

            yield $eventLoop->idle();

            return $caughtException;
        })();

        $this->assertSame($expectedException, $eventLoop->wait($eventLoop->async($generator)));
    }

    public static function knownRejectionPositions(): \Generator
    {
        yield 'before pending promise' => [
            'knownRejectionFirst' => true,
        ];

        yield 'after pending promise' => [
            'knownRejectionFirst' => false,
        ];
    }

    public function testPromiseAllShouldPreserveRejectionOrderWhenEarlierFutureIsAlreadyComplete(): void
    {
        $eventLoop = $this->createEventLoop();
        $expectedException = new \RuntimeException('First rejection');
        $first = $eventLoop->promiseRace($eventLoop->promiseRejected($expectedException));

        try {
            $eventLoop->wait($first);
        } catch (\RuntimeException) {
            // promiseRace's Future is complete, but its wrapper is not marked as settled.
        }

        $promise = $eventLoop->promiseAll($first, $eventLoop->promiseRejected(new \RuntimeException('Second rejection')));

        $this->expectExceptionObject($expectedException);
        $eventLoop->wait($promise);
    }

    public function testRejectionHandledByAnotherLoopShouldBeRemovedFromOriginalLoop(): void
    {
        $originalLoop = $this->createEventLoop();
        $otherLoop = $this->createEventLoop();
        $deferred = $originalLoop->deferred();
        $promise = $originalLoop->async(
            (fn (): \Generator => yield $deferred->getPromise())()
        );
        $expectedException = new \RuntimeException('Original loop rejection');
        $deferred->reject($expectedException);

        $caughtException = null;

        try {
            $otherLoop->wait($promise);
        } catch (\RuntimeException $exception) {
            $caughtException = $exception;
        }

        $this->assertSame($expectedException, $caughtException);
        $this->assertSame('ok', $originalLoop->wait($originalLoop->promiseFulfilled('ok')));
    }

    protected function tearDown(): void
    {
        gc_collect_cycles(); // extensions using an event loop may otherwise leak the file descriptors to the loop
    }
}
