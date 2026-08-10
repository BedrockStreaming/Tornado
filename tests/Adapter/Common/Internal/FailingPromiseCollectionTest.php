<?php

declare(strict_types=1);

namespace M6WebTest\Tornado\Adapter\Common\Internal;

use M6Web\Tornado\Adapter\Common\Internal\FailingPromiseCollection;
use M6Web\Tornado\Promise;
use PHPUnit\Framework\TestCase;

class FailingPromiseCollectionTest extends TestCase
{
    public function testItWatchesAndUnwatchesPromises(): void
    {
        $collection = new FailingPromiseCollection();
        $promise = $this->createPromise();
        $throwable = new \RuntimeException('failed');

        $collection->watchFailingPromise($promise, $throwable);
        $collection->unwatchPromise($promise);

        $collection->throwIfWatchedFailingPromiseExists();
        $this->addToAssertionCount(1);
    }

    public function testItThrowsTheWatchedThrowable(): void
    {
        $collection = new FailingPromiseCollection();
        $throwable = new \RuntimeException('failed');
        $collection->watchFailingPromise($this->createPromise(), $throwable);

        $this->expectExceptionObject($throwable);
        $collection->throwIfWatchedFailingPromiseExists();
    }

    public function testUnwatchingAnUnknownPromiseIsHarmless(): void
    {
        $collection = new FailingPromiseCollection();
        $collection->unwatchPromise($this->createPromise());

        $collection->throwIfWatchedFailingPromiseExists();
        $this->addToAssertionCount(1);
    }

    public function testItEmitsNoDiagnosticWhileWatchingAndUnwatching(): void
    {
        $collection = new FailingPromiseCollection();
        $diagnostics = [];

        set_error_handler(
            static function (int $type, string $message) use (&$diagnostics): bool {
                $diagnostics[] = $message;

                return true;
            },
            \E_ALL
        );

        try {
            for ($i = 0; $i < 10; $i++) {
                $promise = $this->createPromise();
                $collection->watchFailingPromise($promise, new \RuntimeException('failed'));
                $collection->unwatchPromise($promise);
            }
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $diagnostics);
    }

    private function createPromise(): Promise
    {
        return new class implements Promise {
        };
    }
}
