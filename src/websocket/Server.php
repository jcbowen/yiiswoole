<?php

namespace jcbowen\swoole\websocket;

// 引入swoole4服务
use \Swoole\WebSocket\Server as WsServer;
use yii\base\ExitException;

trait Server
{
    // 应用名称
    protected $_app;

    // 应用根目录
    protected $_app_root;

    /**
     * 指定监听的 ip 地址
     *
     * IPv4 使用 127.0.0.1 表示监听本机，0.0.0.0 表示监听所有地址
     * IPv6 使用::1 表示监听本机，:: (相当于 0:0:0:0:0:0:0:0) 表示监听所有地址
     *
     * @var string
     */
    public $_host = '0.0.0.0';

    /**
     * 指定监听的端口
     *
     * @var string
     */
    public $_port = '9410';

    /**
     * 指定运行模式
     *
     * 默认值：SWOOLE_PROCESS 多进程模式（默认）
     * 其它值：SWOOLE_BASE 基本模式
     * @var int
     */
    public $_mode = SWOOLE_PROCESS;

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
    public $_socketType;

    /**
     * 指定运行的是wss还是ws
     *
     * @var string
     */
    public $_type = 'ws';

    /**
     * 配置信息
     *
     * @var array
     */
    public $_yii_config = [];


    /**
     * @var WsServer
     */
    public $_server;

    /**
     * Server constructor.
     * @param string $app 应用目录名称，如：backend
     * @param string $app_root 应用根目录，如："/www/wwwroot/{$app}"；默认为yii2高级版的后端应用根目录
     */
    public function __construct($app = '', $app_root = '')
    {
        $args = func_get_args();
        $this->_app = $args[0];
        $this->_app_root = $args[1] ? $args[1] : sprintf(dirname(dirname(dirname(dirname(dirname(__DIR__))))) . '/%s', $app);
    }

    /**
     *
     */
    public function run()
    {
        if ($this->_type == 'ws') {
            $this->_server = new WsServer($this->_host, $this->_port);
        } else {
            // 暂不支持wss
//            $this->_server = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType | SWOOLE_SSL);
        }
        $this->_server->on('Open', [$this, 'onOpen']);

        $this->_server->on('Message', [$this, 'onMessage']);

        $this->_server->on('Close', [$this, 'onClose']);

        $this->_server->start();
    }

    /**
     * @param $server
     * @param $request
     */
    public function onOpen($server, $request)
    {
//        $server->push($request->fd, "hello, welcome\n");
        echo 'server: handshake success!' . PHP_EOL;
    }

    public function onMessage($server, $frame)
    {
//        echo "Message: {$frame->data}\n";
//        $server->push($frame->fd, "server: {$frame->data}");
        $this->initYii();
    }

    public function onClose($server, $fd)
    {
        echo "client-{$fd} is closed" . PHP_EOL;
    }

    /**
     */
    /*public function callMethod()
    {
        $args = func_get_args();
        $controller = array_shift($args);
        $method = array_shift($args);
        call_user_func_array([$controller, $method], $args);
    }*/

    abstract function initYii();

    public function appRun($application)
    {
        try {
            $application->state = $application::STATE_BEFORE_REQUEST;
            $application->trigger($application::EVENT_BEFORE_REQUEST);

            $application->state = $application::STATE_HANDLING_REQUEST;
            print_r($application->getRequest());die;
            $response = $application->handleRequest($application->getRequest());

            $application->state = $application::STATE_AFTER_REQUEST;
            $application->trigger($application::EVENT_AFTER_REQUEST);

            $application->state = $application::STATE_SENDING_RESPONSE;
            $response->send();

            $application->state = $application::STATE_END;

            return $response->exitStatus;
        } catch (ExitException $e) {
            $application->end($e->statusCode, isset($response) ? $response : null);
            return $e->statusCode;
        }
    }
}