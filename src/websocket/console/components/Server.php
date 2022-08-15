<?php

namespace jcbowen\yiiswoole\websocket\console\components;

use jcbowen\yiiswoole\components\Context;
use Swoole\Process;
use Swoole\Server\Port;
use Swoole\Table;
use Swoole\WebSocket\Server as WsServer;
use Yii;
use yii\base\InvalidRouteException;
use yii\console\Exception;
use yii\console\Response;
use yii\helpers\ArrayHelper;

/**
 * websocket server
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2022/8/5 9:09 AM
 * @package jcbowen\yiiswoole\websocket\console\components
 */
class Server
{
    /** @descripton 服务配置 */
    protected $serverConfig;

    /** @descripton 需要监听的端口 */
    protected $serverPorts;

    /** @descripton 替换websocket的回调 */
    protected $onWebsocket;

    /** @descripton 需要创建的内存级table */
    protected $tablesConfig;

    /**
     * @var array
     */
    public $_tables = [];

    /**
     * @var WsServer
     */
    public $_ws = [];

    /**
     * pid 文件所在位置
     * @var string
     */
    public $pidFile = '';

    /**
     * 所有监听成功的端口
     * @var array
     */
    public $ports = [];

    /**
     * Server constructor.
     * @param array $serverConfig 通用配置
     * @param array $serverPorts 多端口配置
     * @param $onWebsocket
     * @param array $tablesConfig
     */
    public function __construct(array $serverConfig, array $serverPorts, $onWebsocket = null, array $tablesConfig = [])
    {
        $this->serverConfig = $serverConfig;

        $this->serverPorts = $serverPorts;

        $this->onWebsocket = $onWebsocket ?: null;

        $this->tablesConfig = $tablesConfig;

        if (empty($this->serverConfig)) {
            echo '配置信息为空，请检查配置文件' . PHP_EOL;
            die();
        }

        if (!empty($this->serverConfig['pid_file'])) {
            $this->pidFile = $this->serverConfig['pid_file'];
        }

        if (empty($this->pidFile)) {
            echo '请设置pid_file路径' . PHP_EOL;
            die();
        }
    }

    /**
     * 运行websocket服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return WsServer
     * @lasttime: 2022/8/5 2:55 PM
     */
    public function run(): WsServer
    {
        // 根据配置文件创建内存共享table
        if (!empty($this->tablesConfig) && is_array($this->tablesConfig)) {
            foreach ($this->tablesConfig as $ind => $tableConfig) {
                if (empty($tableConfig['column']) || !is_array($tableConfig['column'])) continue;
                $tableSize           = $tableConfig['size'] ?: 1024;
                $conflict_proportion = $tableConfig['conflict_proportion'] ?: 0.2;
                /** @var Table */
                $this->_tables[$ind] = new Table($tableSize, $conflict_proportion);

                foreach ($tableConfig['column'] as $column) {
                    $this->_tables[$ind]->column($column['name'], $column['type'], $column['size']);
                }
                $this->_tables[$ind]->create();
            }
        }

        static $first = '';
        foreach ($this->serverPorts as $k => $port) {
            // 将第一个作为主服务
            if (empty($first)) {
                $this->_ws = new WsServer($port['host'], $port['port'], $port['mode'], $port['socketType']);

                // 将全局配置信息与第一个端口配置信息合并，并生效
                $portConfig = ArrayHelper::merge($this->serverConfig, $port);

                if (empty($portConfig['cert'])) {
                    unset($portConfig['ssl_cert_file'], $portConfig['ssl_key_file']);
                }

                // 将配置中的地址为swoole能理解的绝对地址
                if (!empty($portConfig['pid_file'])) $portConfig['pid_file'] = Yii::getAlias($portConfig['pid_file']);
                if (!empty($portConfig['log_file'])) $portConfig['log_file'] = Yii::getAlias($portConfig['log_file']);

                // 移除不需要的配置项及非swoole的自定义配置项
                unset($portConfig['host'], $portConfig['port'], $portConfig['mode'], $portConfig['socketType'], $portConfig['type']);

                $this->_ws->set($portConfig);

                $first = $k;
            } else {
                // 其他的作为主服务的端口监听
                /**
                 * @var $ports Port
                 */
                $ports = $this->_ws->listen($port['host'], $port['port'], $port['socketType']);

                // 移除不需要的配置，避免全局配置被替换
                unset(
                    $port['host'],
                    $port['port'],
                    $port['mode'],
                    $port['socketType'],
                    $port['type'],

                    $port['daemonize'],
                    $port['pid_file'],
                    $port['log_file'],
                    $port['log_level'],
                    $port['buffer_output_size'],
                    $port['heartbeat_check_interval'],
                    $port['heartbeat_idle_time'],
                    $port['worker_num'],
                    $port['max_wait_time'],
                    $port['reload_async']
                );

                $ports->set($port);
            }
            $this->ports[$k] = "{$port['host']}:{$port['port']}";
        }

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
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return bool
     * @lasttime: 2022/8/5 2:54 PM
     */
    public function stop(): bool
    {
        if ($pid = $this->getPid()) {
            if ($this->onWorkerStop($this->_ws, false)) {
                return Process::kill($pid, SIGTERM);
            } else {
                echo '结束服务被终止' . PHP_EOL;
                return false;
            }
        } else {
            echo '进程未启动，无需停止' . PHP_EOL;
            return false;
        }
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param WsServer $server
     * @param int|bool $workerId
     * @return bool|mixed
     * @lasttime: 2022/8/5 2:54 PM
     */
    public function onWorkerStart(WsServer $server, $workerId)
    {

        echo("服务开始运行, 监听" . json_encode($this->ports) . PHP_EOL);

        Context::setGlobal('WebSocket', [
            'server'   => $server,
            'workerId' => $workerId,
            'on'       => 'start',
            'tables'   => $this->_tables
        ]);

        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onWorkerStart')) {
                return call_user_func_array([$this->onWebsocket, 'onWorkerStart'], [$server, $workerId, $this]);
            }
        }

        return true;
    }

    /**
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param int|bool $workerId
     * @return bool
     * @lasttime: 2022/8/5 2:43 PM
     */
    public function onWorkerStop($server, $workerId): bool
    {
        $global = Context::getGlobal('WebSocket');

        $global['server'] = $server;
        $global['on']     = 'stop';

        Context::setGlobal('WebSocket', $global);

        echo '进程已经停止' . PHP_EOL;

        // 如果接管了onWorkerStop，则执行
        if (!empty($this->onWebsocket) && method_exists($this->onWebsocket, 'onWorkerStop')) {
            return call_user_func_array([$this->onWebsocket, 'onWorkerStop'], [$server, $workerId, $this]);
        }

        Context::delGlobal('WebSocket');

        return true;
    }

    /**
     * 握手成功，开启连接
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param $request
     * @return false|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2022/8/5 2:52 PM
     */
    public function onOpen($server, $request)
    {
        // 如果接管了onOpen，则不再执行
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onOpen')) {
                return call_user_func_array([$this->onWebsocket, 'onOpen'], [$server, $request, $this]);
            }
        }

        echo 'server: handshake success!' . PHP_EOL;

        $_GPC      = ArrayHelper::merge((array)$request->get, (array)$request->post);
        $_GPC['a'] = (string)$_GPC['a'];

        $version = trim($_GPC['v']);
        $route   = $request->server['path_info'];

        $_B     = (array)Context::get('_B');
        $global = (array)Context::getGlobal('WebSocket');

        $_B['WebSocket']['fd']     = $request->fd;
        $_B['WebSocket']['server'] = $server;
        $_B['WebSocket']['frame']  = $request;
        $_B['WebSocket']['on']     = 'open';
        $_B['WebSocket']['params'] = [
            'route'   => $route,
            'version' => $version,
        ];
        $_B['WebSocket']['global'] = $global;

        Context::set('_B', $_B);
        Context::set('_GPC', $_GPC);

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
            'errcode' => 200,
            'errmsg'  => 'handshake success',
            'route'   => $route
        ], JSON_UNESCAPED_UNICODE)));
    }

    /**
     * 监听消息
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param WsServer $server
     * @param $frame
     * @return false|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2022/8/5 3:03 PM
     */
    public function onMessage(WsServer $server, $frame)
    {
        // 如果接管了onMessage，则不再执行
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onMessage')) {
                return call_user_func_array([$this->onWebsocket, 'onMessage'], [$server, $frame, $this]);
            }
        }

        $_B   = Context::get('_B');
        $_GPC = Context::get('_GPC');

        if (empty($_B['WebSocket']['fd'])) return $server->push($frame->fd, "信息丢失，请重新连接");

        // 修改上下文中的信息
        $_B['WebSocket']['on']     = 'message';
        $_B['WebSocket']['server'] = $server;
        $_B['WebSocket']['frame']  = $frame;

        $jsonData = (array)@json_decode($frame->data, true);

        if (empty($jsonData)) {
            return $server->push($frame->fd, stripslashes(json_encode([
                'errcode' => 0,
                'errmsg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE)));
        } else {
            $route = $cacheRoute = $_B['WebSocket']['params']['route'];

            $_GPC = ArrayHelper::merge((array)$_GPC, $jsonData);

            $gpcRoute = trim($_GPC['route']);
            $route    = $gpcRoute ?: $route;

            Context::set('_B', $_B);
            Context::set('_GPC', $_GPC);

            if (empty($route)) return $server->push($frame->fd, stripslashes(json_encode([
                'errcode' => 211,
                'errmsg'  => 'Empty Route',
                'cr'      => $cacheRoute,
                'gr'      => $gpcRoute,
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

    /**
     * 监听关闭
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param $fd
     * @return false|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2022/8/5 2:39 PM
     */
    public function onClose($server, $fd)
    {
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onClose')) {
                return call_user_func_array([$this->onWebsocket, 'onClose'], [$server, $fd, $this]);
            }
        }

        $_B = Context::get('_B');

        echo "client-$fd is closed" . PHP_EOL;

        $_B['WebSocket']['server'] = $server;
        $_B['WebSocket']['frame']  = [];
        $_B['WebSocket']['on']     = 'close';

        $route = $_B['WebSocket']['params']['route'];

        Context::set('_B', $_B);

        if (!empty($route) && $route != '/') {
            try {
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
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return false|string
     * @lasttime: 2022/8/5 2:39 PM
     */
    public function getPid()
    {
        if (empty($this->pidFile)) return false;
        $pid_file = Yii::getAlias($this->pidFile);
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
