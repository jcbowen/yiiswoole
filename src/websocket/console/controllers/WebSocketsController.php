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

use jcbowen\yiiswoole\websocket\console\components\Server;
use yii\console\Controller;
use yii\helpers\ArrayHelper;

class WebSocketsController extends Controller
{
    /**
     * @var string Server
     */
    public $serverClass;

    /**
     * 监听拓展组件
     * @var string
     */
    public $onWebsocket;

    /**
     * 标准的swoole4配置项都可以再此加入
     * @var array
     */
    public $config = [];

    /**
     * 监听多端口时，每个端口的配置信息
     * @var array
     */
    public $ports = [];

    /**
     * swooleTables
     * @var array
     */
    public $tables = [];

    /**
     * @var Server
     */
    public $server;

    /**
     * init
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lasttime: 2021/6/8 1:18 下午
     */
    public function init()
    {
        parent::init();

        if (!($this->server)) {
            foreach ($this->ports as &$item) {
                $item = $this->initPorts($item);
            }

            $onWebsocket = null;
            if (!empty($this->onWebsocket)) {
                if (class_exists($this->onWebsocket)) {
                    $onWebsocket = new $this->onWebsocket();
                }
            }
            $this->server = new $this->serverClass($this->config, $this->ports, $onWebsocket, $this->tables);
        }
    }

    /**
     * swoole默认配置信息
     *
     * @var array
     */
    private $_config = [
        'daemonize'                => false, // 守护进程执行
        'ssl_cert_file'            => '',
        'ssl_key_file'             => '',
        'pid_file'                 => '@runtime/log/websocket.pid',
        'log_file'                 => '@runtime/log/websocket.log',
        'log_level'                => SWOOLE_LOG_DEBUG,
        'buffer_output_size'       => 2 * 1024 * 1024, //配置发送输出缓存区内存尺寸
        'heartbeat_check_interval' => 60,// 心跳检测秒数
        'heartbeat_idle_time'      => 600,// 检查最近一次发送数据的时间和当前时间的差，大于则强行关闭
        'worker_num'               => 1,
        'max_wait_time'            => 60,
        'reload_async'             => true,
    ];

    /**
     * 初始化配置信息
     *
     * @param array $port
     * @return array
     * @lasttime: 2021/6/8 1:17 下午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    private function initPorts(array $port = []): array
    {
        $port = ArrayHelper::merge([
            'host'       => '0.0.0.0',
            'port'       => 9408,
            // 运行的模式 SWOOLE_PROCESS 多进程模式（默认）SWOOLE_BASE 基本模式
            'mode'       => SWOOLE_PROCESS,
            // 功能：指定这组 Server 的类型 默认值：SWOOLE_SOCK_TCP 其它值：SWOOLE_TCP/SWOOLE_SOCK_TCP tcp ipv4 socket; SWOOLE_TCP6/SWOOLE_SOCK_TCP6 tcp ipv6 socket; SWOOLE_UDP/SWOOLE_SOCK_UDP udp ipv4 socket; SWOOLE_UDP6/SWOOLE_SOCK_UDP6 udp ipv6 socket; SWOOLE_UNIX_DGRAM unix socket dgram; SWOOLE_UNIX_STREAM unix socket stream
            'socketType' => SWOOLE_SOCK_TCP,
            // 长连接方式 默认值：'ws' 其它值：'ws' 'wss'
            'type'       => 'ws',
        ], $port);

        $port['port'] = intval($port['port']);

        if ($port['type'] == 'wss') {
            $port['socketType'] = SWOOLE_SOCK_TCP | SWOOLE_SSL;
        }

        $port['config'] = ArrayHelper::merge($this->_config, $port['config']);

        return $port;
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
            return $this->stdout("服务已经停止, 停止监听" . PHP_EOL);
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
