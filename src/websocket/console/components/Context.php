<?php
/**
 * 变量缓存处理
 */

namespace jcbowen\yiiswoole\websocket\console\components;

use Swoole\Coroutine;
use Swoole\ExitException as SwExitException;

class Context
{
    protected static $pool = [];

    protected static $globalPool = [];

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

    static function put($key, $item)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            self::$pool[$cid][$key] = $item;
        }
    }

    static function delete($key = null)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) {
            if ($key) {
                unset(self::$pool[$cid][$key]);
            } else {
                unset(self::$pool[$cid]);
            }
        }
    }

    static function getGlobal($key)
    {
        $cid = Coroutine::getuid();

        if ($cid < 0) return null;
        if (isset(self::$globalPool[$key])) return self::$globalPool[$key];

        return null;
    }

    static function putGlobal($key, $item)
    {
        $cid = Coroutine::getuid();
        if ($cid > 0) self::$globalPool[$key] = $item;
    }

    //------ 用来兼容旧版本的，Begin ------/
    static function getBG(&$_B = [], &$_GPC = [])
    {
        $global = self::get('_BG');
        $_B = $global['_B'];
        $_GPC = $global['_GPC'];

        return [
            '_B'   => $_B,
            '_GPC' => $_GPC,
        ];
    }

    static function putBG($params)
    {
        $global = self::get('_BG');

        $global['_B'] = $params['_B'] ?: $global['_B'];
        $global['_GPC'] = $params['_GPC'] ?: $global['_GPC'];

        self::put('_BG', $global);
        return $global;
    }

    //------ 用来兼容旧版本的，End ------/

    static function swooleExit()
    {
        try {
            exit(0);
        } catch (SwExitException $e) {
            throw new SwExitException($e->getMessage() . PHP_EOL);
        }
    }
}
