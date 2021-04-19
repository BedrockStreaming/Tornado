<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use M6Web\Tornado\Promise;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use RuntimeException;
use function extension_loaded;
use function count;
use function is_callable;
use function usleep;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
final class SwoolePromise implements Promise
{
    const PROMISE_WAIT = 100;

    const STATE_PENDING   = 1;
    const STATE_FULFILLED = 0;
    const STATE_REJECTED  = -1;

    /** @var int */
    protected $state = self::STATE_PENDING;
    
    /** @var mixed */
    private $result;

    private $resolvedCallback;

    /**
     * PromiseCo constructor.
     *
     * @param callable $executor
     */
    public function __construct(callable $executor)
    {
        $this->resolvedCallback = static function() {};

        if (!extension_loaded('swoole')) {
            throw new RuntimeException(
                'SwoolePromise MUST running only in CLI mode with swoole extension.'
            );
        }
        // @codeCoverageIgnoreEnd
        $resolve = function ($value) {
            $this->setResult($value, self::STATE_FULFILLED);
        };
        $reject  = function ($value) {
            $this->setResult($value, self::STATE_REJECTED);
        };
        Coroutine::create(function (callable $executor, callable $resolve, callable $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        }, $executor, $resolve, $reject);
    }

    /**
     * {@inheritDoc}
     *
     * @param callable $promise
     * @return SwoolePromise
     */
    final public static function create(callable $promise): SwoolePromise
    {
        return new static($promise);
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $value
     * @return SwoolePromise
     */
    final public static function resolve($value): SwoolePromise
    {
        return new static(function (callable $resolve) use ($value) {
            $resolve($value);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $value
     * @return SwoolePromise
     */
    final public static function reject($value): SwoolePromise
    {
        return new static(function (callable $resolve, callable $reject) use ($value) {
            $reject($value);
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param callable $onRejected
     * @return SwoolePromise
     */
    final public function catch(callable $onRejected): SwoolePromise
    {
        return $this->then(null, $onRejected);
    }

    /**
     * Change promise state
     *
     * @param integer $state
     * @return void
     */
    final protected function setState(int $state): void
    {
        $this->state = $state;
    }

    /**
     * Promise is pending
     *
     * @return boolean
     */
    final protected function isPending(): bool
    {
        return $this->state == self::STATE_PENDING;
    }

    /**
     * Promise is fulfilled
     *
     * @return boolean
     */
    final protected function isFulfilled(): bool
    {
        return $this->state == self::STATE_FULFILLED;
    }

    /**
     * {@inheritDoc}
     *
     * @param callable|null $onFulfilled
     * @param callable|null $onRejected
     * @return SwoolePromise
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): SwoolePromise
    {
        return self::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            $this->resolvedCallback = function() use ($resolve, $reject, $onFulfilled, $onRejected) {
                $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
                if (!is_callable($callable)) {
                    $resolve($this->result);
                    return;
                }
                try {
                    $resolve($callable($this->result));
                } catch (\Throwable $error) {
                    $reject($error);
                }
            };
            if(!$this->isPending()) {
                ($this->resolvedCallback)();
            }
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param iterable|SwoolePromise[] $promises
     * @return SwoolePromise
     */
    public static function all(iterable $promises): SwoolePromise
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = count($promises);

            $firstError = null;
            $channel    = new Channel($ticks);
            $result     = [];
            $key        = 0;
            foreach ($promises as $promise) {
                if (!$promise instanceof SwoolePromise) {
                    $channel->close();
                    throw new RuntimeException(
                        'Supported only SwoolePromise instance'
                    );
                }
                $promise->then(function ($value) use ($key, &$result, $channel) {
                    $result[$key] = $value;
                    $channel->push(true);
                    return $value;
                }, function ($error) use ($channel, &$firstError) {
                    $channel->push(true);
                    if ($firstError === null) {
                        $firstError = $error;
                    }
                });
                $key++;
            }
            while ($ticks--) {
                $channel->pop();
            }
            $channel->close();

            if ($firstError !== null) {
                $reject($firstError);
                return;
            }

            $resolve($result);
        });
    }

    /**
     * Set resolved result
     *
     * @param mixed $value
     * @return void
     */
    private function setResult($value, $state): void
    {
        if ($value instanceof self) {
            $resolved = false;
            $callable = function ($value) use (&$resolved) {
                $this->setResult($value);
                $resolved = true;
            };
            $value->then($callable, $callable);
            // resolve async locking error (code to remove)
            //while (!$resolved) {
                // @codeCoverageIgnoreStart
                //usleep(self::PROMISE_WAIT);
                // @codeCoverageIgnoreEnd
            //}
        } else if ($this->isPending()) {
            $this->result = $value;
            $this->setState($state);
            ($this->resolvedCallback)();
        }
    }
}
