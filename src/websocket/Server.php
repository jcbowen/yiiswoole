<?php

namespace jcbowen\swoole\websocket;

// 引入swoole4服务
use \Swoole\WebSocket\Server as WsServer;

trait Server
{

    protected $_host = '0.0.0.0';

    protected $_port = '9410';

    protected $_mode = SWOOLE_PROCESS;

    protected $_socketType;

    protected $_type = 'ws';

    protected $_config;

    protected $_server;

    public function __construct($host, $port, $mode, $socketType, $type, $config)
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_mode = $mode;
        $this->_socketType = $socketType;
        $this->_type = $type;
        $this->_config = $config;
    }

    public function run()
    {
        if ($this->_type == 'ws') {
            $this->_server = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType);
        } else {
            $this->_server = new WsServer($this->_host, $this->_port, $this->_mode, $this->_socketType | SWOOLE_SSL);
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
        $server->push($request->fd, "hello, welcome\n");
    }

    public function onMessage($server, $frame)
    {
        echo "Message: {$frame->data}\n";
        $server->push($frame->fd, "server: {$frame->data}");
    }

    public function onClose($server, $fd)
    {
        echo "client-{$fd} is closed\n";
    }

}