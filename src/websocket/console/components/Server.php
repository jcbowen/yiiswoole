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

class Server
{
    protected $serverConfig;

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
     * 局部缓存变量
     *
     * @var array
     */
    private $_cache = [];

    private $_runCallBack = [];

    private $_gpc = [];

    /**
     * Server constructor.
     * @param $serverConfig
     */
    public function __construct($serverConfig)
    {
        $this->serverConfig = $serverConfig;

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
        echo("服务开始运行, 监听" . json_encode($this->ports) . PHP_EOL);
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
        Context::getBG($_B, $_GPC);
        echo 'server: handshake success!' . PHP_EOL;

        $_GPC = ArrayHelper::merge((array)$request->get, (array)$request->post);

        $version = trim($_GPC['v']);

        $_B['WebSocket'] = [
            'server' => $server, 'frame' => $request, 'on' => 'open', 'params' => [
                'version' => $version
            ]
        ];

        $route = $request->server['path_info'];

        $this->_cache[$request->fd] = [
            'route'   => $route,
            'version' => $version,
            'a'       => $_GPC['a']
        ];

        $this->_gpc = $_GPC;
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
        Context::getBG($_B, $_GPC);

        $_B['WebSocket'] = [
            'server' => $server, 'frame' => $frame, 'on' => 'message', 'params' => [
                'version' => $this->_cache[$frame->fd]['version']
            ]
        ];

        $jsonData = (array)@json_decode($frame->data, true);

        if (empty($jsonData)) {
            return $server->push($frame->fd, stripslashes(json_encode([
                'code' => 200,
                'msg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE)));
        } else {
            $_GPC = ArrayHelper::merge((array)$this->_gpc, (array)$_GPC, $jsonData);

            $route = $this->_cache[$frame->fd]['route'];
            if (!empty($_GPC['route'])) $route = $_GPC['route'];

            Context::putBG([
                '_GPC' => $_GPC,
                '_B'   => $_B
            ]);

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
        Context::getBG($_B, $_GPC);

        echo "client-{$fd} is closed" . PHP_EOL;

        $_B['WebSocket'] = ['server' => $server, 'frame' => [], 'on' => 'close'];

        $route = $this->_cache[$fd]['route'];

        $_GPC = ArrayHelper::merge((array)$this->_gpc, (array)$_GPC);

        Context::putBG([
            '_B'   => $_B,
            '_GPC' => $_GPC,
        ]);

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
