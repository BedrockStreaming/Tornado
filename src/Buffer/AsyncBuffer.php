<?php

declare(strict_types=1);

namespace M6Web\Tornado\Buffer;

use M6Web\Tornado\EventLoop;
use M6Web\Tornado\Promise;
use Psr\Log\LoggerInterface;

class AsyncBuffer
{
    /** @var array<string, AsyncBufferItem>  */
    private array $buffer = [];
    private bool $waitingForIdle = false;
    /** @var callable(array<AsyncBufferItem>): Promise */
    private mixed $flusherGeneratorBuilder;
    private ?float $bufferingStartTime = null;

    /**
     * @param callable(array<AsyncBufferItem>): Promise $flusherGeneratorBuilder An awaitable callback that will receive all buffered inputs
     * @param int|null                                  $bufferSize              A maximum buffer size after which the buffer will get automatically flushed
     * @param float|null                                $bufferingMinWaitSecond  Buffering time in second before triggering any flush (on EventLoop idle)
     * @param float|null                                $bufferingMaxWaitSecond  Buffering time in second after automatically triggering flush
     */
    public function __construct(
        callable $flusherGeneratorBuilder,
        private readonly EventLoop $eventLoop,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?int $bufferSize = null,
        private readonly ?float $bufferingMinWaitSecond = null,
        private readonly ?float $bufferingMaxWaitSecond = null,
    ) {
        $this->flusherGeneratorBuilder = $flusherGeneratorBuilder;
    }

    /**
     * Register a set of argument in the Buffer that will be passed on flushed
     */
    public function register(mixed ...$args): Promise
    {
        $asyncBufferItem = new AsyncBufferItem($args, $this->eventLoop->deferred());
        $this->buffer[] = $asyncBufferItem;

        $this->logger?->debug('Registered async request', ['class' => __CLASS__, 'args' => $args]);

        if ($this->bufferingStartTime === null) {
            $this->bufferingStartTime = microtime(true);
        }

        if (!$this->waitingForIdle) {
            $this->waitingForIdle = true;
            $this->eventLoop->async($this->awaitFlushing());
        }

        if ($this->shouldFlush()) {
            $this->flush();
        }

        return $asyncBufferItem->getPromise();
    }

    /**
     * Wait for Event Loop to be idle to trigger a flush
     */
    private function awaitFlushing(): \Generator
    {
        if ($this->bufferingMinWaitSecond !== null && $this->secondsSinceBuffering() < $this->bufferingMinWaitSecond) {
            $msToWait = \intval(ceil(($this->bufferingMinWaitSecond - $this->secondsSinceBuffering()) * 1000));
            $waitPromise = $this->eventLoop->delay($msToWait);
            $this->logger?->debug('Awaiting event loop with delay', ['class' => __CLASS__, 'delay' => $msToWait]);
        } else {
            $waitPromise = $this->eventLoop->idle();
            $this->logger?->debug('Awaiting idle event loop', ['class' => __CLASS__]);
        }

        yield $waitPromise;
        $this->flush();
        $this->waitingForIdle = false;
    }

    private function shouldFlush(): bool
    {
        if ($this->bufferSize !== null && \count($this->buffer) >= $this->bufferSize) {
            return true;
        }

        if ($this->bufferingMaxWaitSecond !== null && $this->secondsSinceBuffering() >= $this->bufferingMaxWaitSecond) {
            return true;
        }

        return false;
    }

    private function flush(): void
    {
        $this->logger?->debug('Flushing buffer', ['class' => __CLASS__, 'buffer_size' => \count($this->buffer)]);

        /**
         * Capture and flush the current buffer
         * @param array<AsyncBufferItem> $buffer
         * @throws UnresolvedItemsException
         */
        $wrappedGenerator = function (array $buffer) : \Generator {
            try {
                yield \call_user_func($this->flusherGeneratorBuilder, $buffer);

                $this->logger?->debug('End flushing buffer', ['class' => __CLASS__]);
            } catch (\Throwable $t) {
                $this->logger?->warning('Failure to flush buffer', ['class' => __CLASS__, 'throwable' => $t, 'throwable_class' => get_debug_type($t)]);
                foreach ($buffer as $item) {
                    if ($item->isPending()) {
                        $item->reject($t);
                    }
                }
            }

            $pendingItems = [];
            foreach ($buffer as $item) {
                if ($item->isPending()) {
                    $pendingItems[] = $item;
                }
            }

            if (count($pendingItems) > 0) {
                throw new UnresolvedItemsException($pendingItems);
            }
        };

        $this->eventLoop->async($wrappedGenerator($this->buffer));
        $this->buffer = [];
        $this->bufferingStartTime = null;
    }

    private function secondsSinceBuffering(): float
    {
        // $this->bufferingStartTime should never be null at this point
        return microtime(true) - $this->bufferingStartTime;
    }
}
