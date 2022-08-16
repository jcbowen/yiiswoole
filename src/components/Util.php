<?php
/**
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2022/8/15 9:49 AM
 * @package jcbowen\yiiswoole\components
 */

namespace jcbowen\yiiswoole\components;

class Util
{
    /**
     * 是否以指定的字符串开头
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $haystack
     * @param $needle
     * @return bool
     * @lasttime: 2022/8/15 9:51 AM
     */
    public static function startsWith($haystack, $needle): bool
    {
        return substr_compare($haystack, $needle, 0, strlen($needle)) === 0;
    }

    /**
     * 是否以指定字符串结尾
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $haystack
     * @param $needle
     * @return bool
     * @lasttime: 2022/8/15 9:50 AM
     */
    public static function endsWith($haystack, $needle): bool
    {
        return substr_compare($haystack, $needle, -strlen($needle)) === 0;
    }

    /**
     * 将数组中所有的yii格式路径转换为绝对路径
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $config
     * @return mixed
     * @lasttime: 2022/8/15 10:16 AM
     */
    public static function translateArrayFilePath(&$config)
    {
        foreach ($config as &$value) {
            if (is_string($value)) {
                if (self::startsWith($value, '@')) {
                    $value = \Yii::getAlias($value);
                }
            } else if (is_array($value)) {
                self::translateArrayFilePath($value);
            }
        }
        return $config;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $string
     * @return bool
     * @lasttime: 2022/8/16 1:19 PM
     */
    public static function isJson($string): bool
    {
        $json = @json_decode($string, true);
        return $json && (json_last_error() == JSON_ERROR_NONE);
    }
}
