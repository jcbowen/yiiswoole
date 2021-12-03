<?php
/**
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2021/4/25 12:53 下午
 * @package jcbowen\yiiswoole\websocket\console\components
 */

namespace jcbowen\yiiswoole\websocket\console\components;

use Swoole\WebSocket\Server as WsServer;
use Swoole\Process;
use Yii;
use yii\console\Exception;
use yii\helpers\ArrayHelper;

class Server
{
    protected $tablesConfig;

    protected $serverConfig;

    protected $onWebsocket;

    /** @var \Swoole\Table */
    protected $contextTable;

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
     * @param $serverConfig
     * @param $onWebsocket
     */
    public function __construct($serverConfig, $onWebsocket = null, $tablesConfig = [])
    {
        $this->tablesConfig = $tablesConfig;

        $this->serverConfig = $serverConfig;

        $this->onWebsocket = $onWebsocket ?: null;

        foreach ($serverConfig as $item) {
            if (!empty($item['config']['pid_file'])) {
                $this->pidFile = $item['config']['pid_file'];
                break;
            }
        }

        if (empty($this->pidFile)) {
            echo '请在主服务设置pid_file路径' . PHP_EOL;
            die();
        }

        if (empty($this->serverConfig)) {
            echo '配置信息为空，请检查websocket多端口监听的配置文件' . PHP_EOL;
            die();
        }

        // 上下文缓存table
        $this->contextTable = new \Swoole\Table(10240, 0.2);
        $this->contextTable->column('fd', \Swoole\Table::TYPE_INT);
        $this->contextTable->column('route', \Swoole\Table::TYPE_STRING, '240');
        $this->contextTable->column('version', \Swoole\Table::TYPE_STRING, '32');
        $this->contextTable->column('a', \Swoole\Table::TYPE_STRING, '240');
        $this->contextTable->create();
    }

    /**
     * 运行websocket服务
     *
     * @return WsServer
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/25 1:13 下午
     */
    public function run(): WsServer
    {
        // 遍历创建Table
        if (!empty($this->tablesConfig) && is_array($this->tablesConfig)) {
            foreach ((array)$this->tablesConfig as $ind => $tableConfig) {
                if (empty($tableConfig['column']) || !is_array($tableConfig['column'])) continue;
                $tableSize = $tableConfig['size'] ?: 1024;
                $conflict_proportion = $tableConfig['conflict_proportion'] ?: 0.2;
                /** @var \Swoole\Table */
                $this->_tables[$ind] = new \Swoole\Table($tableSize, $conflict_proportion);

                foreach ($tableConfig['column'] as $column) {
                    $this->_tables[$ind]->column($column['name'], $column['type'], $column['size']);
                }
                $this->_tables[$ind]->create();
            }
        }

        static $first = '';

        foreach ($this->serverConfig as $k => $config) {
            // 将第一个作为主服务
            if (empty($first)) {
                $this->_ws = new WsServer($config['host'], $config['port'], $config['mode'], $config['socketType']);
                $this->_ws->set($config['config']);
                $this->pidFile = $config['config']['pid_file'];
                $first = $k;
            } else {
                // 其他的作为主服务的端口监听
                /**
                 * @var $ports \Swoole\Server\Port
                 */
                $ports = $this->_ws->listen($config['host'], $config['port'], $config['socketType']);
                unset($config['config']['pid_file']);
                $ports->set($config['config']);
            }
            $this->ports[$k] = "{$config['host']}:{$config['port']}";
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
     * @return false
     * @author Bowen
     * @email bowen@jiuchet.com
     * @lastTime 2021/4/26 1:49 下午
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
     * @param \Swoole\Server $server
     * @param int|bool $workerId
     * @return bool|mixed
     * @lasttime: 2021/5/21 10:13 上午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onWorkerStart(\Swoole\Server $server, $workerId)
    {
        Context::getBG($_B, $_GPC);

        echo("服务开始运行, 监听" . json_encode($this->ports) . PHP_EOL);

        $_B['WebSocket'] = [
            'server'   => $server,
            'workerId' => $workerId,
            'on'       => 'start',
            'tables'   => $this->_tables
        ];

        Context::putBG(['_B' => $_B]);

        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onWorkerStart')) {
                return call_user_func_array([$this->onWebsocket, 'onWorkerStart'], [$server, $workerId, $this]);
            }
        }

        return true;

    }

    /**
     *
     * @param \Swoole\Server $server
     * @param int|bool $workerId
     * @return bool
     * @lasttime: 2021/6/22 3:41 下午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onWorkerStop($server, $workerId)
    {
        Context::getBG($_B, $_GPC);

        $_B['WebSocket'] = [
            'server'   => $server,
            'workerId' => $workerId,
            'on'       => 'stop',
            'tables'   => $this->_tables
        ];

        Context::putBG(['_B' => $_B]);

        echo '进程已经停止' . PHP_EOL;
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onWorkerStop')) {
                return call_user_func_array([$this->onWebsocket, 'onWorkerStop'], [$server, $workerId, $this]);
            }
        }

        return true;
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
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onOpen')) {
                return call_user_func_array([$this->onWebsocket, 'onOpen'], [$server, $request, $this]);
            }
        }

        Context::getBG($_B, $_GPC);
        echo 'server: handshake success!' . PHP_EOL;

        $_GPC = ArrayHelper::merge((array)$request->get, (array)$request->post);

        $version = trim($_GPC['v']);

        $_B['WebSocket'] = [
            'fd'     => $request->fd,
            'server' => $server,
            'frame'  => $request,
            'on'     => 'open',
            'params' => [
                'version' => $version
            ],
            'tables' => $this->_tables
        ];

        $route = $request->server['path_info'];

        $this->contextTable->set($request->fd, [
            'fd'      => $request->fd,
            'route'   => $route,
            'version' => $version,
            'a'       => (string)$_GPC['a']
        ]);

        Context::putGlobal('fd_gpc_' . $request->fd, $_GPC);
        Context::putBG([
            '_B'   => $_B,
            '_GPC' => $_GPC,
        ]);

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
     * @param WsServer $server
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
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onMessage')) {
                return call_user_func_array([$this->onWebsocket, 'onMessage'], [$server, $frame, $this]);
            }
        }

        Context::getBG($_B, $_GPC);

        $_B['WebSocket'] = [
            'fd'     => $frame->fd,
            'server' => $server,
            'frame'  => $frame,
            'on'     => 'message',
            'params' => [
                'version' => $this->contextTable->get($frame->fd, 'version')
            ],
            'tables' => $this->_tables
        ];

        $jsonData = (array)@json_decode($frame->data, true);

        if (empty($jsonData)) {
            return $server->push($frame->fd, stripslashes(json_encode([
                'code' => 200,
                'msg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE)));
        } else {
            $_GPC = ArrayHelper::merge((array)Context::getGlobal('fd_gpc_' . $frame->fd), (array)$_GPC, $jsonData);

            $route = $cacheRoute = $this->contextTable->get($frame->fd, 'route');
            $gpcRoute = trim($_GPC['route']);
            if (!empty($gpcRoute)) $route = $gpcRoute;

            Context::putBG([
                '_GPC' => $_GPC,
                '_B'   => $_B
            ]);

            if (empty($route)) return $server->push($frame->fd, stripslashes(json_encode([
                'code'  => 211,
                'msg'   => 'Empty Route',
                'cr'    => $cacheRoute,
                'gr'    => $gpcRoute,
                'cache' => $this->contextTable->get($frame->fd)
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
     * @param $server
     * @param $fd
     * @return false|int|mixed|\yii\console\Response
     * @throws \yii\base\InvalidRouteException
     * @lasttime: 2021/6/8 1:19 下午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function onClose($server, $fd)
    {
        if ($this->onWebsocket) {
            if (method_exists($this->onWebsocket, 'onClose')) {
                return call_user_func_array([$this->onWebsocket, 'onClose'], [$server, $fd, $this]);
            }
        }

        Context::getBG($_B, $_GPC);

        echo "client-{$fd} is closed" . PHP_EOL;

        $_B['WebSocket'] = [
            'fd'     => $fd,
            'server' => $server,
            'frame'  => [],
            'on'     => 'close',
            'tables' => $this->_tables
        ];

        $route = $this->contextTable->get($fd, 'route');

        $_GPC = ArrayHelper::merge((array)Context::getGlobal('fd_gpc_' . $fd), (array)$_GPC);

        Context::putBG([
            '_B'   => $_B,
            '_GPC' => $_GPC,
        ]);

        // 断开连接的时候，清理掉，节约内存
        $this->contextTable->del($fd);

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
     * @return false|string
     * @lasttime: 2021/6/8 1:19 下午
     * @author Bowen
     * @email bowen@jiuchet.com
     */
    public function getPid()
    {
        if (empty($this->pidFile)) return false;
        $pid_file = $this->pidFile;
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
