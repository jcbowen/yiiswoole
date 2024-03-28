<?php

namespace Jcbowen\yiiswoole\tcp\console\controllers;

use yii\console\Controller;

/**
 * Class BaseController
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2024/3/28 7:12 PM
 * @package Jcbowen\yiiswoole\tcp\console\controllers
 */
class BaseController extends Controller
{
    /** @var \Swoole\Server|null $server Swoole\Server 对象 */
    protected ?\Swoole\Server $server = null;

    /** @var int $fd 连接的文件描述符 */
    protected int $fd = 0;

    /** @var int $reactorId TCP 连接所在的 Reactor 线程 ID */
    protected int $reactorId = 0;

    /** @var string|null $data 收到的数据内容，可能是文本或者二进制内容 */
    protected ?string $data = null;


    /**
     * {@inheritdoc}
     */
    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'server',
            'fd',
            'reactorId',
            'data',
        ]);
    }
}
