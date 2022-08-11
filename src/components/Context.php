<?php
/**
 * 上下文类
 * @descripton: 在 Context 类中，使用 Coroutine::getuid 获取了协程 ID，然后隔离不同协程之间的全局变量，协程退出时清理上下文数据。
 */

namespace jcbowen\yiiswoole\components;

use Swoole\Coroutine;

class Context
{
    protected static $pool = [];

    protected static $globalPool = [];

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $key
     * @return mixed|null
     * @lasttime: 2022/8/5 9:05 AM
     */
    static function get($key)
    {
        $cid = Coroutine::getuid();
        if ($cid < 0) {
            return null;
        }
        if (isset(self::$pool[$cid][$key])) {
            return self::$pool[$cid][$key];
        }
        return null;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $key
     * @param $item
     * @lasttime: 2022/8/5 9:06 AM
     */
    static function set($key, $item)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            self::$pool[$cid][$key] = $item;
        }
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $key
     * @lasttime: 2022/8/5 9:06 AM
     */
    static function del($key = null)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            if ($key == null) {
                unset(self::$pool[$cid]);
            } elseif (isset(self::$pool[$cid][$key])) {
                unset(self::$pool[$cid][$key]);
            }
        }
    }

    //--- 未隔离，Begin ---/
    static function getGlobal($key)
    {
        return self::$globalPool[$key];
    }

    static function setGlobal($key, $item)
    {
        self::$globalPool[$key] = $item;
    }

    static function delGlobal($key = null)
    {
        if ($key == null) {
            self::$globalPool = [];
        } elseif (isset(self::$globalPool[$key])) {
            unset(self::$globalPool[$key]);
        }
    }
    //--- 未隔离，End ---/

    static function swooleExit()
    {
        try {
            exit(0);
        } catch (\Swoole\ExitException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }
}
