<?php

namespace Jcbowen\yiiswoole\tcp\console\components;

use Jcbowen\JcbaseYii2\components\ErrCode;
use Jcbowen\yiiswoole\components\Context;
use Jcbowen\yiiswoole\components\Util;
use Swoole\Process;
use Swoole\Server\Port;
use Swoole\Table;
use Swoole\Server as SwServer;
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
 * @descripton server for tcp
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lasttime: 2023/11/16 2:47 PM
 * @package Jcbowen\yiiswoole\tcp\console\components
 */
class Server extends Component
{
    // -----
    /**
     * @var array 标准的swoole4配置项都可以在此加入
     */
    protected array $serverConfig;

    /**
     * @var array 监听多端口时，每个端口的配置信息
     */
    protected array $serverPorts;

    /**
     * @descripton 需要创建的内存级table们
     */
    protected array $tablesConfig;

    /** @var Controller console控制器 */
    protected Controller $Controller;

    // -----
    /**
     * @var array 实例化的内存级table都会存储在此
     */
    public array $_tables = [];

    /**
     * @var SwServer|null tcp实例
     */
    public ?SwServer $_ms = null;

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
     * 运行tcp服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return SwServer
     * @lasttime: 2023/11/16 2:48 PM
     */
    public function run(): SwServer
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
                $this->_ms = new SwServer($port['host'], $port['port'], $port['mode'], $port['socketType']);

                // 将全局配置信息与第一个端口配置信息合并，并生效
                $portConfig = ArrayHelper::merge($this->serverConfig, $port);

                // 将配置中的地址为swoole能理解的绝对地址
                Util::translateArrayFilePath($portConfig);

                // 如果不需要证书，则清理掉相关参数
                if (empty($portConfig['cert']))
                    unset($portConfig['ssl_cert_file'], $portConfig['ssl_key_file']);

                // 移除不需要的配置项及非swoole的自定义配置项
                unset($portConfig['host'], $portConfig['port'], $portConfig['mode'], $portConfig['socketType'], $portConfig['cert']);

                $this->_ms->set($portConfig);

                $first = $k;
            } else {
                // 其他的端口作为主服务的端口监听
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
            if ($this->onWorkerStop($this->_ms, null)) {
                return Process::kill($pid, SIGTERM);
            } else {
                $this->Controller->stdout('结束服务被终止' . PHP_EOL, BaseConsole::FG_YELLOW);
                return false;
            }
        } else {
            $this->Controller->stdout('进程未启动，无需停止' . PHP_EOL, BaseConsole::FG_YELLOW);
            return false;
        }
    }

    /**
     * 监听worker启动
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param SwServer $server
     * @param int|null $workerId
     * @lasttime: 2022/8/5 2:54 PM
     */
    public function onWorkerStart(SwServer $server, ?int $workerId = null)
    {
        $this->Controller->stdout("Start Websocket Worker, Ports:" . json_encode($this->ports) . PHP_EOL, BaseConsole::FG_GREEN);

        $global                = Context::getGlobal('TCP');
        $global['server']      = $server;
        $global['workerIds'][] = $workerId;
        $global['workerIds']   = array_unique($global['workerIds']);
        $global['on']          = 'start';
        $global['tables']      = $this->_tables;

        Context::setGlobal('TCP', $global);
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
        $global = Context::getGlobal('TCP');

        $global['server'] = $server;
        $global['on']     = 'stop';
        // 移除当前workerId
        $global['workerIds'] = array_diff($global['workerIds'], [$workerId]);

        Context::setGlobal('TCP', $global);

        $this->Controller->stdout('The worker has been stopped' . PHP_EOL, BaseConsole::FG_RED);

        Context::delGlobal('TCP');

        return true;
    }

    /**
     * 有新连接进入时，在 worker 进程中回调。
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param SwServer $server
     * @param int $fd 连接的文件描述符
     * @param int $reactorId 连接所在的 Reactor 线程 ID
     * @return bool
     * @lasttime: 2023/11/16 2:53 PM
     */
    public function onConnect(SwServer $server, int $fd, int $reactorId): bool
    {
        $this->Controller->stdout("Connect Success (fd: $fd)" . PHP_EOL . PHP_EOL, BaseConsole::FG_GREEN);

        $_B = (array)Context::get('_B');

        // 初始化上下文变量
        $_B['TCP']['server']    = $server;
        $_B['TCP']['fd']        = $fd;
        $_B['TCP']['reactorId'] = $reactorId;
        $_B['TCP']['on']        = 'connect';

        Context::set('_B', $_B);

        return $server->send($fd, json_encode([
            'errcode' => ErrCode::SUCCESS,
            'errmsg'  => 'connect success',
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 接收到数据时回调此函数，发生在 worker 进程中。
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @param SwServer $server
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     * @return mixed|void
     * @throws InvalidRouteException
     * @lasttime: 2022/8/15 2:58 PM
     */
    public function onReceive(SwServer $server, int $fd, int $reactorId, string $data)
    {
        $_B   = Context::get('_B');
        $_GPC = Context::get('_GPC');

        // 如果全局变量中的fd不存在，就意味着数据丢失了，需要客户端重新发起连接
        if (empty($_B['TCP']['fd'])) {
            $server->send($fd, json_encode([
                'errcode' => ErrCode::LOST_CONNECTION,
                'errmsg'  => 'connect info lost',
            ], JSON_UNESCAPED_UNICODE));
            return $server->close($fd);
        }

        // 修改上下文中的信息
        $_B['TCP']['on']        = 'receive';
        $_B['TCP']['server']    = $server;
        $_B['TCP']['reactorId'] = $reactorId;

        $jsonData = Util::isJson($data) ? (array)@json_decode($data, true) : $data;
        $jsonData = $jsonData ?: $data; // 避免因json解析失败导致数据丢失的情况

        // 空数据为触发心跳
        if (empty($jsonData))
            return $server->send($fd, json_encode([
                'errcode' => ErrCode::SUCCESS,
                'errmsg'  => 'Heart Success'
            ], JSON_UNESCAPED_UNICODE));

        if (is_array($jsonData)) {
            $route = $cacheRoute = $_B['TCP']['params']['route'];

            $_GPC = ArrayHelper::merge((array)$_GPC, $jsonData);

            $gpcRoute = trim($_GPC['route']);
            $route    = $gpcRoute ?: $route;

            // 如果route不存在，不知道应该由哪个路由进行处理，只能进行报错处理
            if (empty($route))
                return $server->send($fd, json_encode([
                    'errcode' => ErrCode::PARAMETER_ERROR,
                    'errmsg'  => 'Empty Route',
                    'cr'      => $cacheRoute,
                    'gr'      => $gpcRoute,
                ], JSON_UNESCAPED_UNICODE));

            $_B['TCP']['params']['route'] = $route;

            // 更新上下文中的信息
            Context::set('_B', $_B);
            Context::set('_GPC', $_GPC);

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
            return $server->send($fd, json_encode([
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
        $_B = Context::get('_B');

        $this->Controller->stdout("client-$fd is closed" . PHP_EOL);

        $_B['TCP']['server'] = $server;
        $_B['TCP']['on']     = 'close';

        Context::set('_B', $_B);

        $route = $_B['TCP']['params']['route'];
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
