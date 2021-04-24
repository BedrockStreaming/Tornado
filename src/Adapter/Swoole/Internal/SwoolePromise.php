<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use function count;
use M6Web\Tornado\Promise;
use RuntimeException;
use Swoole\Coroutine;

/**
 * @internal
 * ⚠️ You must NOT rely on this internal implementation
 */
final class SwoolePromise implements Promise
{
    private $result;
    private $onResolve;

    const STATUS_RESOLVE = 1;
    const STATUS_ERROR = -1;

    /**
     * Promise constructor.
     */
    public function __construct(callable $executor)
    {
        $this->onResolve = function ($status, $value) {
            $this->result = [$status, $value];
        };

        $resolve = function ($value) {
            ($this->onResolve)(self::STATUS_RESOLVE, $value);
        };
        $reject = function ($error) {
            ($this->onResolve)(self::STATUS_ERROR, $error);
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
     */
    final public static function create(callable $promise): SwoolePromise
    {
        return new static($promise);
    }

    /**
     * {@inheritDoc}
     *
     * @param mixed $value
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
     */
    final public static function reject($value): SwoolePromise
    {
        return new static(function (callable $resolve, callable $reject) use ($value) {
            $reject($value);
        });
    }

    /**
     * {@inheritDoc}
     */
    final public function catch(callable $onRejected): SwoolePromise
    {
        return $this->then(null, $onRejected);
    }

    /**
     * {@inheritDoc}
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): SwoolePromise
    {
        if ($this->result === null) {
            return self::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
                $this->onResolve = function ($status, $value) use ($resolve, $reject, $onFulfilled, $onRejected) {
                    if ($status === self::STATUS_RESOLVE) {
                        if ($onFulfilled !== null) {
                            $onFulfilled($value);
                        }
                        $resolve($value);
                    } elseif ($status === self::STATUS_ERROR) {
                        if ($onRejected !== null) {
                            $onRejected($value);
                        }
                        $reject($value);
                    }
                };
            });
        }

        return self::create(function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            if ($this->result[0] === self::STATUS_RESOLVE) {
                $value = $this->result[1];
                if ($onFulfilled !== null) {
                    $onFulfilled($value);
                }
                $resolve($value);
            } elseif ($this->result[0] === self::STATUS_ERROR) {
                $error = $this->result[1];
                if ($onRejected !== null) {
                    $onRejected($error);
                }
                $reject($error);
            }
        });
    }

    /**
     * {@inheritDoc}
     *
     * @param iterable|SwoolePromise[] $promises
     */
    public static function all(iterable $promises): SwoolePromise
    {
        return self::create(function (callable $resolve, callable $reject) use ($promises) {
            $ticks = count($promises);

            $firstError = null;
            $result = [];
            $key = 0;
            foreach ($promises as $promise) {
                if (!$promise instanceof SwoolePromise) {
                    throw new RuntimeException('Supported only SwoolePromise instance');
                }
                $promise->then(function ($value) use ($key, &$result, &$ticks, $resolve) {
                    $result[$key] = $value;
                    $ticks--;
                    if ($ticks === 0) {
                        ksort($result);
                        $resolve($result);
                    }

                    return $value;
                }, function ($error) use (&$firstError, &$ticks, $reject) {
                    $ticks--;
                    if ($firstError === null) {
                        $firstError = $error;
                        $reject($firstError);
                    }
                });
                $key++;
            }
        });
    }
}
