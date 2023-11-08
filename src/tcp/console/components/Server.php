<?php

namespace Jcbowen\yiiswoole\tcp\console\components;

use Jcbowen\yiiswoole\components\Context;
use Jcbowen\yiiswoole\components\Util;
use Swoole\Process;
use Swoole\Server\Port;
use Swoole\Table;
use Swoole\Server as SwServer;
use Yii;
use yii\base\InvalidRouteException;
use yii\console\Exception;
use yii\console\Response;
use yii\helpers\ArrayHelper;

/**
 * TCP Server
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2022/8/15 2:15 PM
 * @package Jcbowen\yiiswoole\tcp\console\components
 */
class Server
{
    /** @descripton 服务配置 */
    protected $serverConfig;

    /** @descripton 需要监听的端口 */
    protected $serverPorts;

    /** @descripton 替换server的监听回调 */
    protected $onServer;

    /** @descripton 需要创建的内存级table */
    protected $tablesConfig;

    /**
     * @var array
     */
    public $_tables = [];

    /**
     * 主服务
     * @var SwServer
     */
    public $_ms = [];

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
     * @param $onServer
     * @param array $tablesConfig
     */
    public function __construct(array $serverConfig, array $serverPorts, $onServer = null, array $tablesConfig = [])
    {
        $this->serverConfig = $serverConfig;

        $this->serverPorts = $serverPorts;

        $this->onServer = $onServer ?: null;

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
     * 运行服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return SwServer
     * @lasttime: 2022/8/5 2:55 PM
     */
    public function run(): SwServer
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
                $this->_ms = new SwServer($port['host'], $port['port'], $port['mode'], $port['socketType']);

                // 将全局配置信息与第一个端口配置信息合并，并生效
                $portConfig = ArrayHelper::merge($this->serverConfig, $port);

                // 将配置中的地址为swoole能理解的绝对地址
                Util::translateArrayFilePath($portConfig);

                if (empty($portConfig['cert'])) {
                    unset($portConfig['ssl_cert_file'], $portConfig['ssl_key_file']);
                }

                // 移除不需要的配置项及非swoole的自定义配置项
                unset($portConfig['host'], $portConfig['port'], $portConfig['mode'], $portConfig['socketType'], $portConfig['cert']);

                $this->_ms->set($portConfig);

                $first = $k;
            } else {
                // 其他的作为主服务的端口监听
                /**
                 * @var $ports Port
                 */
                $ports = $this->_ms->listen($port['host'], $port['port'], $port['socketType']);

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

        $this->_ms->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->_ms->on('WorkerStop', [$this, 'onWorkerStop']);
        $this->_ms->on('Connect', [$this, 'onConnect']);
        $this->_ms->on('Receive', [$this, 'onReceive']);
        $this->_ms->on('Close', [$this, 'onClose']);
        $this->_ms->start();

        return $this->_ms;
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
            if ($this->onWorkerStop($this->_ms, false)) {
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
     * @param SwServer $server
     * @param int|bool $workerId
     * @return bool|mixed
     * @lasttime: 2022/8/5 2:54 PM
     */
    public function onWorkerStart(SwServer $server, $workerId)
    {

        echo("服务开始运行, 监听" . json_encode($this->ports) . PHP_EOL);

        Context::setGlobal('TCP', [
            'server'   => $server,
            'workerId' => $workerId,
            'on'       => 'start',
            'tables'   => $this->_tables
        ]);

        if ($this->onServer) {
            if (method_exists($this->onServer, 'onWorkerStart')) {
                return call_user_func_array([$this->onServer, 'onWorkerStart'], [$server, $workerId, $this]);
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
        $global = Context::getGlobal('TCP');

        $global['server'] = $server;
        $global['on']     = 'stop';

        Context::setGlobal('TCP', $global);

        echo '进程已经停止' . PHP_EOL;

        // 如果接管了onWorkerStop，则执行
        if (!empty($this->onServer) && method_exists($this->onServer, 'onWorkerStop')) {
            return call_user_func_array([$this->onServer, 'onWorkerStop'], [$server, $workerId, $this]);
        }

        Context::delGlobal('TCP');

        return true;
    }

    /**
     * 有新的连接进入时，在 worker 进程中回调。
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param SwServer $server
     * @param int $fd
     * @param int $reactorId
     * @return mixed|void
     * @lasttime: 2022/8/15 2:58 PM
     */
    public function onConnect(SwServer $server, int $fd, int $reactorId)
    {
        echo 'server: Connect Success!' . PHP_EOL;

        $_B     = (array)Context::get('_B');
        $global = (array)Context::getGlobal('TCP');

        $_B['TCP']['fd']     = $fd;
        $_B['TCP']['server'] = $server;
        $_B['TCP']['on']     = 'connect';
        $_B['TCP']['global'] = $global;

        Context::set('_B', $_B);

        // 如果接管了onConnect，则不再执行后续操作
        if ($this->onServer) {
            if (method_exists($this->onServer, 'onConnect')) {
                return call_user_func_array([$this->onServer, 'onConnect'], [$server, $fd, $reactorId]);
            }
        }

        return $server->send($fd, stripslashes(json_encode([
            'errcode' => 0,
            'errmsg'  => 'connect success',
        ], JSON_UNESCAPED_UNICODE)));
    }

    /**
     * 接收到数据时回调此函数，发生在 worker 进程中。
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param SwServer $server
     * @param int $fd
     * @param int $reactor_id
     * @param string $data
     * @return mixed|void
     * @throws InvalidRouteException
     * @lasttime: 2022/8/15 2:58 PM
     */
    public function onReceive(SwServer $server, int $fd, int $reactor_id, string $data)
    {
        $_B   = Context::get('_B');
        $_GPC = Context::get('_GPC');

        // 信息丢失，请重新连接
        if (empty($_B['TCP']['fd'])) return $server->send($fd, stripslashes(json_encode([
            'errcode' => 1,
            'errmsg'  => 'connect info lost',
        ], JSON_UNESCAPED_UNICODE)));

        // 修改上下文中的信息
        $_B['TCP']['on']     = 'message';
        $_B['TCP']['server'] = $server;

        $jsonData = Util::isJson($data) ? (array)@json_decode($data, true) : $data;

        // 空数据为触发心跳
        if (empty($jsonData)) return $server->send($fd, stripslashes(json_encode([
            'errcode' => 0,
            'errmsg'  => 'Heart Success'
        ], JSON_UNESCAPED_UNICODE)));

        if (is_array($jsonData)) {
            $route = $cacheRoute = $_B['TCP']['params']['route'];

            $_GPC = ArrayHelper::merge((array)$_GPC, $jsonData);

            $gpcRoute = trim($_GPC['route']);
            $route    = $gpcRoute ?: $route;

            if (empty($route)) return $server->send($fd, stripslashes(json_encode([
                'errcode' => 9002010,
                'errmsg'  => 'Empty Route',
                'cr'      => $cacheRoute,
                'gr'      => $gpcRoute,
            ], JSON_UNESCAPED_UNICODE)));

            $_B['TCP']['params']['route'] = $route;

            Context::set('_B', $_B);
            Context::set('_GPC', $_GPC);

            // 如果接管了onMessage，则不再执行
            if ($this->onServer) {
                if (method_exists($this->onServer, 'onReceive')) {
                    return call_user_func_array([$this->onServer, 'onReceive'], [$server, $fd, $reactor_id, $data]);
                }
            }

            // 根据json数据中的路由转发到控制器内进行处理
            try {
                return Yii::$app->runAction($route);
            } catch (Exception $e) {
                Yii::info($e);
                echo($e->getMessage());
                return false;
            }
        }else{
            // 如果接管了onMessage，则不再执行
            if ($this->onServer) {
                if (method_exists($this->onServer, 'onReceive')) {
                    return call_user_func_array([$this->onServer, 'onReceive'], [$server, $fd, $reactor_id, $data]);
                }
            }

            // 数据格式错误
            return $server->send($fd, stripslashes(json_encode([
                'errcode' => 9002015,
                'errmsg'  => 'Data Format Error',
            ], JSON_UNESCAPED_UNICODE)));
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
        $_B = Context::get('_B');

        echo "client-$fd is closed" . PHP_EOL;

        $_B['TCP']['server'] = $server;
        $_B['TCP']['frame']  = [];
        $_B['TCP']['on']     = 'close';

        Context::set('_B', $_B);

        if ($this->onServer) {
            if (method_exists($this->onServer, 'onClose')) {
                return call_user_func_array([$this->onServer, 'onClose'], [$server, $fd, $this]);
            }
        }

        $route = $_B['TCP']['params']['route'];
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
