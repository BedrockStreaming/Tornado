<?php

namespace M6Web\Tornado\Adapter\Swoole\Internal;

use Swoole\Coroutine;
use Swoole\Exception;

class Wait
{
    private int $cid = -1;

    private static array $cancel_list = [];

    public function __destruct()
    {
        if ($this->cid !== -1 && $this->cid !== Coroutine::getCid()) {
            Coroutine::resume($this->cid);
        } else {
            self::$cancel_list[$this->cid] = true;
        }
    }

    public static function make()
    {
        return new static();
    }

    /**
     * @throws Exception
     */
    public static function wait(Wait &$wait): void
    {
        if ($wait->cid !== -1) {
            throw new Exception('Already waiting, cannot wait again.');
        }
        $cid = Coroutine::getCid();
        $wait->cid = $cid;
        $wait = null;
        if (!isset(self::$cancel_list[$cid])) {
            Coroutine::yield();
        } else {
            unset(self::$cancel_list[$cid]);
        }
    }
}
