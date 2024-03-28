<?php

namespace Jcbowen\yiiswoole\websocket\console\controllers;

use yii\console\Controller;

/**
 * Class BaseController
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2024/3/28 7:12 PM
 * @package Jcbowen\yiiswoole\websocket\console\controllers
 */
class BaseController extends Controller
{
    /** @var \Swoole\WebSocket\Server|null $server \Swoole\WebSocket\Server 对象 */
    protected ?\Swoole\WebSocket\Server $server = null;

    /** @var \Swoole\WebSocket\Frame|null $frame Swoole\WebSocket\Frame 对象，包含了客户端发来的数据帧信息 */
    protected ?\Swoole\WebSocket\Frame $frame = null;

    /** @var int $fd 客户端连接的 ID */
    protected int $fd = 0;

    /**
     * {@inheritdoc}
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'server',
            'frame',
            'fd',
        ]);
    }
}
