<?php
/**
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2021/4/25 12:53 下午
 * @package jcbowen\yiiswoole\websocket\console\components
 */

namespace jcbowen\yiiswoole\websocket\console\components;

use Closure;
use Swoole\WebSocket\Server as WsServer;
use Swoole\Process;
use Yii;
use yii\console\Exception;
use yii\helpers\ArrayHelper;

class WebSocketServer
{
    /**
     * 指定监听的 ip 地址
     *
     * IPv4 使用 127.0.0.1 表示监听本机，0.0.0.0 表示监听所有地址
     * IPv6 使用::1 表示监听本机，:: (相当于 0:0:0:0:0:0:0:0) 表示监听所有地址
     *
     * @var string
     */
    protected $_host;

    /**
     * 指定监听的端口
     *
     * @var string
     */
    protected $_port;

    /**
     * 指定运行模式
     *
     * 默认值：SWOOLE_PROCESS 多进程模式（默认）
     * 其它值：SWOOLE_BASE 基本模式
     * @var int
     */
    protected $_mode;

    /**
     * 指定这组 Server 的类型
     *
     * 默认值：无
     * 其它值：
     * SWOOLE_TCP/SWOOLE_SOCK_TCP tcp ipv4 socket
     * SWOOLE_TCP6/SWOOLE_SOCK_TCP6 tcp ipv6 socket
     * SWOOLE_UDP/SWOOLE_SOCK_UDP udp ipv4 socket
     * SWOOLE_UDP6/SWOOLE_SOCK_UDP6 udp ipv6 socket
     * SWOOLE_UNIX_DGRAM unix socket dgram
     * SWOOLE_UNIX_STREAM unix socket stream
     *
     * @var int
     */
    protected $_socketType;

    /**
     * 指定运行的是wss还是ws
     *
     * @var string
     */
    protected $_type;

    /**
     * 配置信息
     *
     * @var array
     */
    protected $_config = [
        'daemonize'                => false, // 守护进程执行
        'ssl_cert_file'            => '',
        'ssl_key_file'             => '',
        'pid_file'                 => __DIR__ . '/../runtime/log/websocket.pid',
        'log_file'                 => __DIR__ . '/../runtime/log/websocket.log',
        'log_level'                => SWOOLE_LOG_DEBUG,
        'buffer_output_size'       => 2 * 1024 * 1024, //配置发送输出缓存区内存尺寸
        'heartbeat_check_interval' => 60,// 心跳检测秒数
        'heartbeat_idle_time'      => 600,// 检查最近一次发送数据的时间和当前时间的差，大于则强行关闭
        'worker_num'               => 1,
        'max_wait_time'            => 60,
        'reload_async'             => true,
    ];

    /**
     * @var WsServer
     */
    public $_ws;

    /**
     * 局部缓存变量
     *
     * @var array
     */
    private $_cache = [];

    private $_runCallBack = [];

    /**
     * WebSocketServer constructor.
     * @param $host
     * @param $port
     * @param $mode
     * @param $socketType
     * @param $type
     * @param $config
     */
    public function __construct($host, $port, $mode, $socketType, $type, $config)
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_mode = $mode;
        $this->_socketType = $socketType;
        $this->_type = $type;
        $this->_config = ArrayHelper::merge($this->_config, $config);
    }

    /**
     * 运行websocket服务
     *
     * @return WsServer
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/25 1:13 下午
     */
    public function run($callbacks = []): WsServer
    {
        $this->_runCallBack = $callbacks;

        if ($this->_type == 'ws') {
            $this->_ws = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType);
        } else {
            // wss
            $this->_ws = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType | SWOOLE_SSL);
        }
        $this->_ws->set($this->_config);

        $this->_ws->on('WorkerStart', [$this, 'onWorkerStart']);

        $this->_ws->on('WorkerStop', [$this, 'onWorkerStop']);

        $this->_ws->on('Open', [$this, 'onOpen']);

        $this->_ws->on('Message', [$this, 'onMessage']);

        $this->_ws->on('Close', [$this, 'onClose']);

        $this->_ws->start();

        return $this->_ws;
    }

    /**
     * 结束服务
     *
     * @return false
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/26 1:49 下午
     */
    public function stop(): bool
    {
        if ($pid = $this->getPid()) {
            return Process::kill($pid, SIGTERM);
        } else {
            echo '进程未启动，无需停止' . PHP_EOL;
            return false;
        }
    }

    /**
     *
     * @param $server
     * @param $workerId
     * @lasttime: 2021/5/21 10:13 上午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onWorkerStart($server, $workerId)
    {
        echo("服务开始运行, 监听 {$this->_host}:{$this->_port}" . PHP_EOL);
        $callback = $this->_runCallBack['onWorkerStart'];
        if (is_object($callback) && ($callback instanceof Closure)) {
            $callback($server, $workerId);
        }
    }

    /**
     *
     * @param $server
     * @param $workerId
     * @lasttime: 2021/5/21 10:27 上午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onWorkerStop($server, $workerId)
    {
        echo '进程已经停止' . PHP_EOL;
        $callback = $this->_runCallBack['onWorkerStop'];
        if (is_object($callback) && ($callback instanceof Closure)) {
            $callback($server, $workerId);
        }
    }

    /**
     * 握手成功，开启连接
     *
     * @param $server
     * @param $request
     * @return false|int|mixed|\yii\console\Response
     * @throws \yii\base\InvalidRouteException
     * @lasttime: 2021/5/21 10:14 上午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onOpen($server, $request)
    {
        global $_B, $_GPC;
        echo 'server: handshake success!' . PHP_EOL;

        $_B['WebSocket'] = ['server' => $server, 'frame' => $request, 'on' => 'open'];

        $_GPC = ArrayHelper::merge((array)$request->get, (array)$request->post);
        $route = $request->server['path_info'];

        $this->_cache[$request->fd] = [
            'route' => $route,
            'a'     => $_GPC['a']
        ];

        // 有route的情况才执行
        if (!empty($route) && $route !== '/') {
            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                echo($e->getMessage());
                return false;
            }
        }

        return $server->push($request->fd, stripslashes(json_encode([
            'code'  => 200,
            'msg'   => 'handshake success',
            'route' => $route
        ], JSON_UNESCAPED_UNICODE)));
    }

    /**
     * 监听消息
     *
     * @param $server
     * @param $frame
     *
     * @return false|int|mixed|\yii\console\Response
     * @throws \yii\base\InvalidRouteException
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/25 1:00 下午
     *
     */
    public function onMessage($server, $frame)
    {
        global $_GPC, $_B;
        $_B['WebSocket'] = ['server' => $server, 'frame' => $frame, 'on' => 'message'];

        $jsonData = (array)@json_decode($frame->data, true);

        if (empty($jsonData)) {
            return $server->push($frame->fd, stripslashes(json_encode([
                'code' => 200,
                'msg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE)));
        } else {
            $_GPC = ArrayHelper::merge($_GPC, $jsonData);

            $route = $this->_cache[$frame->fd]['route'];
            if (!empty($_GPC['route'])) $route = $_GPC['route'];

            if (empty($route)) return $server->push($frame->fd, stripslashes(json_encode([
                'code' => 211,
                'msg'  => 'Empty Route'
            ], JSON_UNESCAPED_UNICODE)));

            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                echo($e->getMessage());
                return false;
            }
        }
    }

    public function onClose($server, $fd)
    {
        global $_B;

        echo "client-{$fd} is closed" . PHP_EOL;

        $_B['WebSocket'] = ['server' => $server, 'frame' => [], 'on' => 'close'];

        $route = $this->_cache[$fd]['route'];
        $_GPC['a'] = $this->_cache[$fd]['a'];

        if (!empty($route) && $route != '/') {
            try {
                unset($this->_cache[$fd]);
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                echo($e->getMessage());
            }
        }
        return false;
    }

    /**
     * 获取pid
     *
     * @return false|string
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/26 1:52 下午
     *
     */
    public function getPid()
    {
        $pid_file = $this->_config['pid_file'];
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if (posix_getpgid($pid)) {
                return $pid;
            } else {
                unlink($pid_file);
            }
        }
        return false;
    }
}
