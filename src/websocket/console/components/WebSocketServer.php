<?php
/**
 *
 * @author Bowen
 * @email 3308725087@qq.com
 * @lastTime 2021/4/25 12:53 下午
 * @package jcbowen\yiiswoole\websocket\console\components
 */

namespace jcbowen\yiiswoole\websocket\console\components;


use Swoole\WebSocket\Server as WsServer;
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
    protected $_config = [];


    /**
     * @var WsServer
     */
    public $_server;

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
        $this->_config = $config;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/25 1:13 下午
     *
     *
     * @return WsServer
     */
    public function run(): WsServer
    {
        if ($this->_type == 'ws') {
            $this->_server = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType);
        } else {
            // wss
            $this->_server = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType | SWOOLE_SSL);
        }
        $this->_server->set($this->_config);

        $this->_server->on('Open', [$this, 'onOpen']);

        $this->_server->on('Message', [$this, 'onMessage']);

        $this->_server->on('Close', [$this, 'onClose']);

        $this->_server->start();

        return $this->_server;
    }

    /**
     * 握手成功，开启连接
     *
     * @param $server
     * @param $request
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/25 12:58 下午
     *
     */
    public function onOpen($server, $request)
    {
        echo 'server: handshake success!' . PHP_EOL;
        $server->push($request->fd, stripslashes(json_encode([
            'code' => 200,
            'msg'  => 'handshake success'
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
        global $_GPC;
        $_GPC = ArrayHelper::merge($_GPC, (array)json_decode($frame->data, true));
        $route = $_GPC['route'];

        if (empty($route)) $route = '/';

        unset($_GPC['route']);

        try {
            return Yii::$app->runAction($route, $_GPC);
        } catch (Exception $e) {
            Yii::info($e);
            echo($e->getMessage());
            return false;
        }
    }

    public function onClose($server, $fd)
    {
        echo "client-{$fd} is closed" . PHP_EOL;
    }
}