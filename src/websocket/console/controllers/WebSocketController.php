<?php
/**
 * WebSocket 启停控制器
 *
 * @author Bowen
 * @email bowen@jiuchet.com
 * @lastTime 2021/4/25 9:38 上午
 * @package Jcbowen\yiiswoole\websocket\console\controllers
 */

namespace Jcbowen\yiiswoole\websocket\console\controllers;

use Jcbowen\yiiswoole\components\Util;
use Jcbowen\yiiswoole\websocket\console\components\Server;
use Yii;
use yii\base\Exception;
use yii\console\Controller;
use yii\helpers\FileHelper;

class WebSocketController extends Controller
{
    /**
     * @var string Server
     */
    public string $serverClass = Server::class;

    /**
     * @var array 标准的swoole4配置项
     */
    public array $serverConfig = [];

    /**
     * @var array 监听多端口时，每个端口的配置信息
     */
    public array $serverPorts = [];

    /**
     * @var array 需要创建的内存级table们
     */
    public array $tablesConfig = [];

    /**
     * @var Server websocket服务的实例
     */
    public Server $server;

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();

        foreach ($this->serverPorts as &$item)
            $item = $this->initPorts($item);

        $serverInitConfig = [
            'Controller' => $this
        ];
        if (!empty($this->serverConfig))
            $serverInitConfig['serverConfig'] = $this->serverConfig;
        if (!empty($this->serverPorts))
            $serverInitConfig['serverPorts'] = $this->serverPorts;
        if (!empty($this->tablesConfig))
            $serverInitConfig['tablesConfig'] = $this->tablesConfig;
        $this->server = new $this->serverClass($serverInitConfig);
    }

    /**
     * swoole默认配置信息
     *
     * @var array
     */
    private array $_config = [
        'cert' => false,
    ];

    /**
     * 初始化配置信息
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     * @param array $port
     * @return array
     * @lasttime: 2021/6/8 1:17 下午
     */
    private function initPorts(array $port = []): array
    {
        $port = Util::ArrayMerge([
            'host'       => '0.0.0.0',
            'port'       => 9408,
            // 运行的模式 SWOOLE_PROCESS 多进程模式（默认）SWOOLE_BASE 基本模式
            'mode'       => SWOOLE_PROCESS,
            // 功能：指定这组 Server 的类型 默认值：SWOOLE_SOCK_TCP 其它值：SWOOLE_TCP/SWOOLE_SOCK_TCP tcp ipv4 socket; SWOOLE_TCP6/SWOOLE_SOCK_TCP6 tcp ipv6 socket; SWOOLE_UDP/SWOOLE_SOCK_UDP udp ipv4 socket; SWOOLE_UDP6/SWOOLE_SOCK_UDP6 udp ipv6 socket; SWOOLE_UNIX_DGRAM unix socket dgram; SWOOLE_UNIX_STREAM unix socket stream
            'socketType' => SWOOLE_SOCK_TCP,
            // 证书类型 默认值：null 其它值：'ssl'
            'cert'       => null,
        ], $port);

        $port['port'] = intval($port['port']);

        if ($port['cert'] === 'ssl')
            $port['socketType'] = SWOOLE_SOCK_TCP | SWOOLE_SSL;

        return Util::ArrayMerge($this->_config, $port);
    }

    /**
     * 启动服务
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @lasttime: 2023/11/14 4:04 PM
     */
    public function actionStart()
    {
        // 检查pid_file目录是否存在，如果不存在则创建
        if (!empty($this->server->serverConfig['pid_file'])) {
            $pid_file = Yii::getAlias($this->server->serverConfig['pid_file']);
            if (!is_dir(dirname($pid_file))) {
                try {
                    FileHelper::createDirectory(dirname($pid_file), 0755);
                } catch (Exception $e) {
                    $this->stdout('Failed to create directory: ' . dirname($pid_file) . PHP_EOL);
                    exit(1);
                }
                // 分配给www用户
                chown(dirname($pid_file), 'www');
            }
        }
        // 判断log_file文件是否存在，如果不存在则创建
        if (!empty($this->server->serverConfig['log_file'])) {
            $log_file = Yii::getAlias($this->server->serverConfig['log_file']);
            // 检查log_file目录是否存在，如果不存在则创建
            if (!is_dir(dirname($log_file))) {
                try {
                    FileHelper::createDirectory(dirname($log_file), 0755);
                } catch (Exception $e) {
                    $this->stdout('Failed to create directory: ' . dirname($log_file) . PHP_EOL);
                    exit(1);
                }
                // 分配给www用户
                chown(dirname($log_file), 'www');
            }
        }
        $this->server->run();
        
        return true;
    }

    /**
     * 停止进程
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @return bool
     * @lasttime: 2023/11/14 4:02 PM
     */
    public function actionStop(): bool
    {
        if ($this->server->stop()) {
            $this->stdout("Service stopped, listening ceased." . PHP_EOL);
            return true; // 表示服务已成功停止
        } else {
            $this->stdout("Failed to stop the service." . PHP_EOL);
            return false; // 表示服务未能成功停止
        }
    }

    /**
     * 重启进程
     *
     * @author Bowen
     * @email bowen@jiuchet.com
     *
     * @lasttime: 2023/11/14 4:03 PM
     */
    public function actionRestart()
    {
        $this->stdout('Initiating restart...' . PHP_EOL);
        $this->actionStop();

        $maxTime = 119; // 最大等待时间，以0.5秒为间隔
        $time    = 0;
        while (posix_getpgid($pid = $this->server->getPid()) && !empty($pid) && $time <= $maxTime) {
            usleep(500000); // 每0.5秒执行一次
            $this->stdout("Pid: $pid, Checking..." . PHP_EOL);
            $time++;
        }

        // 超过1分钟就视为停止失败
        if ($time > $maxTime) {
            $this->stdout("Stop timeout..." . PHP_EOL);
            exit(1); // 退出并表示失败状态
        }

        // 如果仍能获取到pid，则意味着进程意外终止
        if ($this->server->getPid() !== false) {
            $this->stdout("Stop error: please stop manually." . PHP_EOL);
            exit(1); // 退出并表示失败状态
        }

        $this->stdout("Stopped successfully. Initiating restart..." . PHP_EOL);
        $this->actionStart();

        return true;
    }
}
