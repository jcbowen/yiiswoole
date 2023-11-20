<?php

namespace Jcbowen\yiiswoole\components;

/**
 * Class Data
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2023/11/20 4:55 PM
 * @package Jcbowen\yiiswoole\components
 */
class ContactData
{
    /**
     * @var array 根据fd分离的数据池
     */
    protected static array $pool = [];

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param int $fd 该连接的文件描述符
     * @param string $key 数据key
     * @return mixed|null
     * @lasttime: 2023/11/20 4:58 PM
     */
    public static function get(int $fd, string $key)
    {
        if ($fd < 1)
            return null;

        return empty($key) ? static::$pool[$fd] : (self::$pool[$fd][$key] ?? null);
    }


    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param int $fd
     * @param string $key
     * @param mixed $content 存储内容
     * @return bool
     * @lasttime: 2023/11/20 5:02 PM
     */
    public static function set(int $fd, string $key, $content): bool
    {
        if ($fd < 1)
            return false;

        if (empty($key))
            self::$pool[$fd] = $content;
        else
            self::$pool[$fd][$key] = $content;

        return true;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param int $fd
     * @param string|null $key 如果为null，表示清空整个fd的数据
     * @lasttime: 2023/11/20 5:02 PM
     */
    public static function del(int $fd, string $key = null)
    {
        if ($fd > 0)
            if ($key == null)
                unset(self::$pool[$fd]);
            elseif (isset(self::$pool[$fd][$key]))
                unset(self::$pool[$fd][$key]);
    }
}
