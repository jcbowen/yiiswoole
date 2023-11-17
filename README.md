# yiiSwoole

### 介绍

Yii2 + Swoole4

目前实现了websocket服务器，同时支持了高性能共享内存Table的使用

### 不兼容说明

使用了usleep，所以不兼容win环境

### 前提

服务器中需要安装swoole4

（如果是宝塔搭建的环境，直接在使用的php版本中安装swoole4扩展）

由于某些跟踪调试的 PHP 扩展大量使用了全局变量，可能会导致 Swoole 协程发生崩溃。请关闭以下相关扩展：

```  
xdebug、phptrace、aop、molten、xhprof、phalcon（Swoole 协程无法运行在 phalcon 框架中）
```

### 安装教程

composer执行

```shell
composer require "jcbowen/yiiswoole"
```

或者在 `composer.json` 加入

```
"jcbowen/yiiswoole": "^4.0"
```

### 配置说明

在`console/config/main.php`的controllerMap中加入配置

```php
        'websocket' => [
            'class'        => \Jcbowen\yiiswoole\websocket\console\controllers\WebSocketController::class,
            'serverClass'  => \Jcbowen\yiiswoole\websocket\console\components\Server::class, // 可不填，默认值
            'serverConfig' => [
                'daemonize'                => true,// 守护进程执行
                'heartbeat_check_interval' => 60, // 启用心跳检测，默认为false
                'heartbeat_idle_time'      => 120, // 连接最大允许空闲的时间，启用心跳检测的情况下，如未设置，默认未心跳检测的两倍
                'pid_file'                 => '@runtime/yiiswoole/websocket.pid',
                'log_file'                 => '@runtime/yiiswoole/websocket.log',
                'log_level'                => SWOOLE_LOG_ERROR,
                'buffer_output_size'       => 2 * 1024 * 1024, //配置发送输出缓存区内存尺寸
                'worker_num'               => 1,
                'max_wait_time'            => 60,
                'reload_async'             => true,
            ],
            'serverPorts'  => [
                // 第一个为websocket主服务
                'ws' => [
                    'host' => '0.0.0.0',
                    'port' => 9408,
                    'cert' => false, // 证书类型 默认值：false 其它值：'ssl'
                ]
            ],
            'tablesConfig' => [],
        ],
```

### 使用

```shell
# 启动 
php yii websocket/start
# 停止 
php yii websocket/stop
# 重启 
php yii websocket/restart
```

### 运行说明

##### websocket客户端向服务器发送json字符串，如：

##### 如果握手的时候，携带了目录路径，该路径将会作为route缓存起来；请求中如果携带了route字段，则替换缓存中的route

```json
{
  "route": "site/test",
  "message": "这是一条来自websocket客户端的消息"
}
```

##### 通过执行```\Jcbowen\yiiswoole\components\Context::get('_B');```方法，可以读取上下文中缓存的信息；

##### 通过执行```\Jcbowen\yiiswoole\components\Context::get('_GPC');```方法，可以读取上下文中缓存的get数据；

##### 其中server和frame会被缓存到```_B```中；

##### 接收到的json会被转为数组后缓存到```_GPC```中；

##### 携带的目录代表的是监听到动作后转发到哪个路由(由于通过console运行的进程，所以这里的路由指的是console里的路由)。

```php
// 这里展示onMessage的源码，用来理解实现原理
    public function onMessage(WsServer $server, $frame)
    {
        $_B   = Context::get('_B');
        $_GPC = Context::get('_GPC');

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
        $jsonData = $jsonData ?: $frame->data; // 避免json解析失败会导致数据丢失的情况

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

            // 如果不携带route，就不知道应该由哪个路由进行处理
            if (empty($route))
                return $server->push($frame->fd, json_encode([
                    'errcode' => ErrCode::PARAMETER_ERROR,
                    'errmsg'  => 'Empty Route',
                    'cr'      => $cacheRoute,
                    'gr'      => $gpcRoute,
                ], JSON_UNESCAPED_UNICODE));

            $_B['WebSocket']['params']['route'] = $route;

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
            return $server->push($frame->fd, json_encode([
                'errcode' => ErrCode::ILLEGAL_FORMAT,
                'errmsg'  => 'Data Format Error',
            ], JSON_UNESCAPED_UNICODE));
        }
    }

```

##### 总结：jcbowen/yiiswoole插件会在websocket触发onmessage时，根据route调用对应的控制器方法，并将websocket服务和接收到数据分别存放到```_B```与```_GPC```中；

### 在控制器方法中使用

```php
class SiteController extends Controller
{    
    public function actionTest()
    {
        $_B = Context::get('_B');
        $_GPC = Context::get('_GPC');
        
        // 可以根据$_B['WebSocket']['on']判断是通过什么方式转发过来的
        // $_B['WebSocket']['on']的值有start/stop/open/message/close

        /** @var \swoole\websocket\server $ws */
        $ws = $_B['WebSocket']['server'];
        $frame = $_B['WebSocket']['frame'];
        
        $tables = $_B['WebSocket']['tables'];
        /** @var \Swoole\Table $table */
        $table = $tables['test_table'];
        
        return $ws->push($frame->fd, $_GPC['message']);
    }
    
}
```
