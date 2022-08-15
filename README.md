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

```
composer require "jcbowen/yiiswoole"
```

或者在 `composer.json` 加入

```
"jcbowen/yiiswoole": "^3.0"
```

### 配置说明

在`console/config/main.php`的controllerMap中加入配置

```
        'websocket' => [
            'class'       => 'jcbowen\yiiswoole\websocket\console\controllers\WebSocketController',
            'serverClass' => 'jcbowen\yiiswoole\websocket\console\components\Server',
            'onWebsocket' => '', // 如果需要自行处理websocket服务的监听事件，可以在此处配置，如：console\components\onWebsocket
            'config'      => [
                'daemonize'                => true,// 守护进程执行
                'heartbeat_check_interval' => 60, // 启用心跳检测，默认为false
                'heartbeat_idle_time'      => 120, // 连接最大允许空闲的时间，启用心跳检测的情况下，如未设置，默认未心跳检测的两倍
                'pid_file'                 => '@runtime/logs/websocket.pid',
                'log_file'                 => '@runtime/logs/websocket.log',
                'log_level'                => SWOOLE_LOG_ERROR,
                'buffer_output_size'       => 2 * 1024 * 1024, //配置发送输出缓存区内存尺寸
                'worker_num'               => 1,
                'max_wait_time'            => 60,
                'reload_async'             => true,
            ],
            'ports'       => [
                // 第一个为websocket主服务
                'ws' => [
                    'host' => '0.0.0.0',
                    'port' => 9408,
                    'cert' => false, // 证书类型 默认值：false 其它值：'ssl'
                ]
            ],
            'tables'      => [],
        ],
```

### 使用
```
# 启动 
php yii websocket/start
# 停止 
php yii websocket/stop
# 重启 
php yii websocket/restart
```

### 运行说明

##### websocket客户端向服务器发送json字符串，如：

```
{"route": "site/test", "message": "这是一条来自websocket客户端的消息"}
（如果握手的时候，携带了目录路径，该路径将会作为route缓存起来；请求中如果携带了route字段，则替换缓存中的route）
```
##### 通过执行```\jcbowen\yiiswoole\components\Context::get('_B');```方法，可以读取上下文中缓存的信息；
##### 通过执行```\jcbowen\yiiswoole\components\Context::get('_GPC');```方法，可以读取上下文中缓存的get/post数据；
##### 其中server和frame会被缓存到```_B```中；
##### 接收到的json会被转为数组后缓存到```_GPC```中；
##### 携带的目录代表的是监听到动作后转发到哪个路由(由于通过console运行的进程，所以这里的路由指的是console里的路由)。
```
// 这里展示onMessage的源码，用来理解实现原理
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

```

##### 总结：jcbowen/yiiswoole插件会在websocket触发onmessage时，根据route调用对应的控制器方法，并将websocket服务和接收到数据分别存放到```_B```与```_GPC```中；

### 在控制器方法中使用
```
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
