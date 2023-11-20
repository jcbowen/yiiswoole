<?php

namespace Jcbowen\yiiswoole\websocket\console\components;

use Jcbowen\JcbaseYii2\components\ErrCode;
use Jcbowen\yiiswoole\components\ContactData;
use Jcbowen\yiiswoole\components\Context;
use Jcbowen\yiiswoole\components\Util;
use Swoole\Process;
use Swoole\Server\Port;
use Swoole\Table;
use Swoole\WebSocket\Server as WsServer;
use Yii;
use yii\base\Component;
use yii\base\InvalidArgumentException;
use yii\base\InvalidRouteException;
use yii\console\Controller;
use yii\console\Exception;
use yii\console\Response;
use yii\helpers\ArrayHelper;
use yii\helpers\BaseConsole;

/**
 * Class Server
 *
 * @descripton server for websocket
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2023/11/16 2:47 PM
 * @package Jcbowen\yiiswoole\websocket\console\components
 */
class Server extends Component
{
    // -----
    /**
     * @var array 标准的swoole4配置项都可以在此加入
     */
    public array $serverConfig;

    /**
     * @var array 监听多端口时，每个端口的配置信息
     */
    public array $serverPorts;

    /**
     * @descripton 需要创建的内存级table们
     */
    public array $tablesConfig;

    /** @var Controller console控制器 */
    public Controller $Controller;

    // -----
    /**
     * @var array 实例化的内存级table都会存储在此
     */
    public array $_tables = [];

    /**
     * @var WsServer|null WebSocket实例
     */
    public ?WsServer $_ws = null;

    /**
     * pid 文件所在位置
     * @var string
     */
    public string $pidFile = '';

    /**
     * 所有监听成功的端口
     * @var array
     */
    public array $ports = [];

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        if (empty($this->serverConfig))
            throw new InvalidArgumentException('Configuration is empty. Please check the configuration file.');

        $this->pidFile = $this->serverConfig['pid_file'] ?: '@runtime/yiiswoole/websocket.pid';
    }

    /**
     * 运行websocket服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return WsServer
     * @lasttime: 2023/11/16 2:48 PM
     */
    public function run(): WsServer
    {
        // 如果在配置文件中，配置了内存共享table，则创建
        if (!empty($this->tablesConfig)) {
            foreach ($this->tablesConfig as $ind => $tableConfig) {
                if (empty($tableConfig['column']) || !is_array($tableConfig['column'])) continue;
                $tableSize           = $tableConfig['size'] ?: 1024;
                $conflict_proportion = $tableConfig['conflict_proportion'] ?: 0.2;
                $this->_tables[$ind] = new Table($tableSize, $conflict_proportion);

                foreach ($tableConfig['column'] as $column)
                    $this->_tables[$ind]->column($column['name'], $column['type'], $column['size']);

                $this->_tables[$ind]->create();
            }
        }

        static $first = '';
        foreach ($this->serverPorts as $k => $port) {
            // 将第一个端口作为主服务
            if (empty($first)) {
                $this->_ws = new WsServer($port['host'], $port['port'], $port['mode'], $port['socketType']);

                // 将全局配置信息与第一个端口配置信息合并，并生效
                $portConfig = ArrayHelper::merge($this->serverConfig, $port);

                // 将配置中的地址为swoole能理解的绝对地址
                Util::translateArrayFilePath($portConfig);

                // 如果不需要证书，则清理掉相关参数
                if (empty($portConfig['cert']))
                    unset($portConfig['ssl_cert_file'], $portConfig['ssl_key_file']);

                // 移除不需要的配置项及非swoole的自定义配置项
                unset($portConfig['host'], $portConfig['port'], $portConfig['mode'], $portConfig['socketType'], $portConfig['cert']);

                $this->_ws->set($portConfig);

                $first = $k;
            } else {
                // 其他的端口作为主服务的端口监听
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
                    $port['cert'],

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

                if (!empty($port)) $ports->set($port);
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
     * 停止服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return bool
     * @lasttime: 2023/11/16 2:48 PM
     */
    public function stop(): bool
    {
        if ($pid = $this->getPid()) {
            if ($this->onWorkerStop($this->_ws, null)) {
                return Process::kill($pid, SIGTERM);
            } else {
                $this->Controller->stdout('The end of service is terminated' . PHP_EOL, BaseConsole::FG_YELLOW);
                return false;
            }
        } else {
            $this->Controller->stdout('The process is not started and does not need to be stopped' . PHP_EOL, BaseConsole::FG_YELLOW);
            return false;
        }
    }

    /**
     * 监听worker启动
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param WsServer $server
     * @param int|null $workerId
     * @lasttime: 2023/11/16 2:49 PM
     */
    public function onWorkerStart(WsServer $server, ?int $workerId = null)
    {
        $this->Controller->stdout("Start Websocket Worker, Ports:" . json_encode($this->ports) . PHP_EOL, BaseConsole::FG_GREEN);

        $global                = Context::getGlobal('WebSocket');
        $global['server']      = $server;
        $global['workerIds'][] = $workerId;
        $global['workerIds']   = array_unique($global['workerIds']);
        $global['on']          = 'start';
        $global['tables']      = $this->_tables;

        Context::setGlobal('WebSocket', $global);
    }

    /**
     * 监听worker停止
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param int|null $workerId
     * @return bool
     * @lasttime: 2023/11/16 2:52 PM
     */
    public function onWorkerStop($server, ?int $workerId): bool
    {
        $global = Context::getGlobal('WebSocket');

        $global['server'] = $server;
        $global['on']     = 'stop';

        // 移除当前workerId
        if (!empty($workerId))
            $global['workerIds'] = array_diff($global['workerIds'], [$workerId]);

        Context::setGlobal('WebSocket', $global);

        return true;
    }

    /**
     * 监听open事件
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param $request
     * @return false|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2023/11/16 2:53 PM
     */
    public function onOpen($server, $request)
    {
        $this->Controller->stdout("Handshake Success (fd: $request->fd)" . PHP_EOL . PHP_EOL, BaseConsole::FG_GREEN);

        $_GPC      = (array)$request->get;
        $_GPC['a'] = (string)$_GPC['a'];

        $version = trim($_GPC['v']) ?: '1.0.0';
        $route   = $request->server['path_info'] ?: '';

        $_B = (array)ContactData::get($request->fd, '_B');

        // 初始化上下文变量
        $_B['WebSocket']['fd']      = $request->fd;
        $_B['WebSocket']['server']  = $server;
        $_B['WebSocket']['request'] = $request;
        $_B['WebSocket']['frame']   = [];
        $_B['WebSocket']['on']      = 'open';
        $_B['WebSocket']['params']  = [
            'route'   => $route,
            'version' => $version,
        ];

        ContactData::set($request->fd, '_B', $_B);
        ContactData::set($request->fd, '_GPC', $_GPC);

        // route不为空时，可以根据route自行处理握手成功信息
        if (!empty($route) && $route !== '/')
            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                $this->Controller->stdout($e->getMessage() . PHP_EOL, BaseConsole::FG_RED);
                return false;
            }

        return $server->push($request->fd, json_encode([
            'errcode' => ErrCode::SUCCESS,
            'errmsg'  => 'Handshake Success',
            'route'   => $route
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 监听消息
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param WsServer $server
     * @param $frame
     * @return bool|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2023/11/16 2:59 PM
     */
    public function onMessage(WsServer $server, $frame)
    {
        $_B   = ContactData::get($frame->fd, '_B');
        $_GPC = ContactData::get($frame->fd, '_GPC');

        // 如果全局变量中的fd不存在，就意味着数据丢失了，需要客户端重新发起连接
        if (empty($_B['WebSocket']['fd'])) {
            $server->push($frame->fd, json_encode([
                'errcode' => ErrCode::LOST_CONNECTION,
                'errmsg'  => 'connect info lost',
            ], JSON_UNESCAPED_UNICODE));
            return $server->close($frame->fd);
        }

        // 修改上下文中的信息
        $_B['WebSocket']['on']     = 'message';
        $_B['WebSocket']['server'] = $server;
        $_B['WebSocket']['frame']  = $frame;

        $jsonData = Util::isJson($frame->data) ? (array)@json_decode($frame->data, true) : $frame->data;
        $jsonData = $jsonData ?: $frame->data; // 避免因json解析失败导致数据丢失的情况

        // 空数据为触发心跳
        if (empty($jsonData))
            return $server->push($frame->fd, json_encode([
                'errcode' => ErrCode::SUCCESS,
                'errmsg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE));

        if (is_array($jsonData)) {
            $route = $cacheRoute = $_B['WebSocket']['params']['route'];

            $_GPC = ArrayHelper::merge((array)$_GPC, $jsonData);

            $gpcRoute = trim($_GPC['route']);
            $route    = $gpcRoute ?: $route;

            // 如果route不存在，不知道应该由哪个路由进行处理，只能进行报错处理
            if (empty($route))
                return $server->push($frame->fd, json_encode([
                    'errcode' => ErrCode::PARAMETER_ERROR,
                    'errmsg'  => 'Empty Route',
                    'cr'      => $cacheRoute,
                    'gr'      => $gpcRoute,
                ], JSON_UNESCAPED_UNICODE));

            $_B['WebSocket']['params']['route'] = $route;

            // 更新上下文中的信息
            ContactData::set($frame->fd, '_B', $_B);
            ContactData::set($frame->fd, '_GPC', $_GPC);

            // 根据json数据中的路由转发到控制器内进行处理
            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                $this->Controller->stdout($e->getMessage() . PHP_EOL, BaseConsole::FG_RED);
                return false;
            }
        } else {
            // 数据格式错误
            return $server->push($frame->fd, json_encode([
                'errcode' => ErrCode::ILLEGAL_FORMAT,
                'errmsg'  => 'Data Format Error',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 监听关闭连接
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param $server
     * @param $fd
     * @return false|int|mixed|Response
     * @throws InvalidRouteException
     * @lasttime: 2023/11/16 3:13 PM
     */
    public function onClose($server, $fd)
    {
        $_B = ContactData::get($fd, '_B');

        $this->Controller->stdout("client-$fd is closed" . PHP_EOL);

        $_B['WebSocket']['server'] = $server;
        $_B['WebSocket']['frame']  = [];
        $_B['WebSocket']['on']     = 'close';

        ContactData::set($fd, '_B', $_B);

        $route = $_B['WebSocket']['params']['route'];
        if (!empty($route) && $route != '/') {
            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                $this->Controller->stdout($e->getMessage() . PHP_EOL, BaseConsole::FG_RED);
            }
        }
        return false;
    }

    /**
     * 获取进程id(PID)
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return false|string
     * @lasttime: 2023/11/16 3:42 PM
     */
    public function getPid()
    {
        if (empty($this->pidFile))
            return false;

        $pid_file = Yii::getAlias($this->pidFile);
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if (posix_getpgid($pid))
                return $pid;
            else
                unlink($pid_file);
        }

        return false;
    }
}
