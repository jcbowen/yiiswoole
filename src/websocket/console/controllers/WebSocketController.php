<?php
/**
 *
 * @author Bowen
 * @email 3308725087@qq.com
 * @lastTime 2021/4/25 9:38 上午
 * @package jcbowen\yiiswoole\websocket\console\controllers
 */

namespace jcbowen\yiiswoole\websocket\console\controllers;

use yii\console\Controller;

class WebSocketController extends Controller
{
    public $serverClass;

    public $server;

    /**
     * 指定监听地址
     *
     * IPv4 使用 127.0.0.1 表示监听本机，0.0.0.0 表示监听所有地址
     * IPv6 使用::1 表示监听本机，:: (相当于 0:0:0:0:0:0:0:0) 表示监听所有地址
     *
     * @var string
     */
    public $host = '0.0.0.0';

    /**
     * 指定监听的端口
     *
     * 默认9410
     *
     * @var string
     */
    /**
     * 指定监听的端口
     *
     * @var int 默认9410
     */
    public $port = 9410;

    /**
     * 运行的模式
     *
     * SWOOLE_PROCESS 多进程模式（默认）
     * SWOOLE_BASE 基本模式
     * @var int
     */
    public $mode = SWOOLE_BASE;

    /**
     *
     * 功能：指定这组 Server 的类型
     * 默认值：SWOOLE_SOCK_TCP
     * 其它值：
     * SWOOLE_TCP/SWOOLE_SOCK_TCP tcp ipv4 socket
     * SWOOLE_TCP6/SWOOLE_SOCK_TCP6 tcp ipv6 socket
     * SWOOLE_UDP/SWOOLE_SOCK_UDP udp ipv4 socket
     * SWOOLE_UDP6/SWOOLE_SOCK_UDP6 udp ipv6 socket
     * SWOOLE_UNIX_DGRAM unix socket dgram
     * SWOOLE_UNIX_STREAM unix socket stream
     * @var int
     */
    public $socketType = SWOOLE_SOCK_TCP;

    /**
     * 长连接方式
     *
     * 默认值：'ws'
     * 其它值：
     * 'ws'
     * 'wss'
     * @var string
     */
    public $type = 'ws';

    /**
     * swoole 配置
     *
     * @var array
     */
    public $config = [
        'daemonize'                => false, // 守护进程执行
        'task_worker_num'          => 4,//task进程的数量
        'ssl_cert_file'            => '',
        'ssl_key_file'             => '',
        'pid_file'                 => '',
        'buffer_output_size'       => 2 * 1024 * 1024, //配置发送输出缓存区内存尺寸
        'heartbeat_check_interval' => 60,// 心跳检测秒数
        'heartbeat_idle_time'      => 600,// 检查最近一次发送数据的时间和当前时间的差，大于则强行关闭
    ];

    public function init()
    {
        parent::init();
        if (!$this->server) $this->server = new $this->serverClass($this->host, $this->port, $this->mode, $this->socketType, $this->type, $this->config);
    }

    /**
     * 启动服务
     *
     * @throws \yii\base\Exception
     */
    public function actionStart()
    {
        $this->server->run();
        $this->stdout("服务开始运行： {$this->host}:{$this->port}" . PHP_EOL);
    }

    /**
     * 关闭进程
     */
    public function actionStop()
    {
        $workerId = $this->server->getWorkerId();
        $this->server->stop($workerId);
        $this->stdout("服务已经停止, 停止监听 {$this->host}:{$this->port}" . PHP_EOL);
    }

    /**
     * 重启进程
     *
     * @throws \yii\base\Exception
     */
    public function actionRestart()
    {
        $this->server->reload();
    }
}