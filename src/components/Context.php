<?php

namespace Jcbowen\yiiswoole\components;

use Swoole\Coroutine;
use Swoole\ExitException;

/**
 * Class Context
 *
 * @descripton 协程上下文数据，在 Context 类中，使用 Coroutine::getuid 获取了协程 ID，然后隔离不同协程之间的全局变量，协程退出时清理上下文数据。
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2023/11/14 4:29 PM
 * @package Jcbowen\yiiswoole\components
 */
class Context
{
    /**
     * @var array 会话上下文
     */
    protected static array $pool = [];

    /**
     * @var array 全局上下文
     */
    protected static array $globalPool = [];

    /**
     * 获取会话数据
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $key
     * @return mixed
     * @lasttime: 2023/11/14 4:17 PM
     */
    public static function get(string $key)
    {
        $cid = Coroutine::getuid();
        if ($cid < 1)
            return null;

        return empty($key) ? static::$pool[$cid] : (self::$pool[$cid][$key] ?? null);
    }

    /**
     * 设置会话数据
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string $key
     * @param mixed $content
     * @return bool
     * @lasttime: 2023/11/14 4:19 PM
     */
    public static function set(string $key, $content): bool
    {
        $cid = Coroutine::getuid();
        if ($cid < 1)
            return false;

        if (empty($key))
            self::$pool[$cid] = $content;
        else
            self::$pool[$cid][$key] = $content;

        return true;
    }

    /**
     * 清理会话数据
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string|null $key
     * @lasttime: 2023/11/14 4:33 PM
     */
    public static function del(string $key = null)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0)
            if ($key == null)
                unset(self::$pool[$cid]);
            elseif (isset(self::$pool[$cid][$key]))
                unset(self::$pool[$cid][$key]);
    }

    //--- 未隔离，Begin ---/

    /**
     * 获取全局会话数据
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string|null $key
     * @return mixed
     * @lasttime: 2023/11/14 4:33 PM
     */
    public static function getGlobal(string $key = null)
    {
        return $key == null ? self::$globalPool : self::$globalPool[$key];
    }

    /**
     * 设置全局会话数据
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string|null $key
     * @param mixed $content
     * @lasttime: 2023/11/14 4:34 PM
     */
    public static function setGlobal(string $key, $content)
    {
        if ($key == null)
            self::$globalPool = $content;
        else
            self::$globalPool[$key] = $content;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param string|null $key
     * @lasttime: 2023/11/14 4:36 PM
     */
    public static function delGlobal(string $key = null)
    {
        if ($key == null)
            self::$globalPool = [];
        elseif (isset(self::$globalPool[$key]))
            unset(self::$globalPool[$key]);
    }

    //--- 未隔离，End ---/

    /**
     * 退出swoole
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @lasttime: 2023/11/14 4:37 PM
     */
    public static function swooleExit()
    {
        try {
            exit(0);
        } catch (ExitException $e) {
            echo $e->getMessage() . PHP_EOL;
        }
    }
}
