<?php
/**
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2022/8/15 9:49 AM
 * @package Jcbowen\yiiswoole\components
 */

namespace Jcbowen\yiiswoole\components;

class Util extends \Jcbowen\JcbaseYii2\components\Util
{
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
