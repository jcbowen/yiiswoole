<?php
/**
 * WebSocket 启停控制器
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2021/4/25 9:38 上午
 * @package jcbowen\yiiswoole\websocket\console\controllers
 */

namespace jcbowen\yiiswoole\websocket\console\controllers;

use yii\console\Controller;

class WebSocketController extends Controller
{
    public $serverClass;

    /**
     * @var \jcbowen\yiiswoole\websocket\console\components\WebSocketServer
     */
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
    public $mode = SWOOLE_PROCESS;

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
     * swoole配置信息
     * 接收配置文件传过来的配置信息
     *
     * @var array
     */
    public $config = [];

    public function init()
    {
        parent::init();
        if (!$this->server) $this->server = new $this->serverClass($this->host, $this->port, $this->mode, $this->socketType, $this->type, $this->config);
    }

    /**
     * 启动服务
     *
     * @return mixed
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/26 1:44 下午
     *
     *
     */
    public function actionStart()
    {
        return $this->server->run();
    }

    /**
     * 关闭进程
     */
    public function actionStop()
    {
        if ($this->server->stop()) {
            return $this->stdout("服务已经停止, 停止监听 {$this->host}:{$this->port}" . PHP_EOL);
        }
        return false;
    }

    /**
     * 重启进程
     *
     * @throws \yii\base\Exception
     */
    public function actionRestart()
    {
        $this->stdout('开始重启进程' . PHP_EOL);
        $this->actionStop();
        $time = 0;
        while (posix_getpgid($pid = $this->server->getPid()) && !empty($pid) && $time <= 119) {
            usleep(500000);// 每0.5秒执行一次
            $this->stdout($pid . PHP_EOL);
            $time++;
        }
        // 超过1分钟就是停止失败
        if ($time > 119) {
            $this->stdout("进程停止超时..." . PHP_EOL);
            die();
        }

        if ($this->server->getPid() === false) {
            $this->stdout("进程停止成功，开始重启" . PHP_EOL);
        } else {
            $this->stdout("进程停止错误, 请手动停止" . PHP_EOL);
        }

        $this->server->run();
    }
}
