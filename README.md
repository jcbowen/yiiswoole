# yiiSwoole

### 介绍

Yii2+Swoole4

目前仅实现了websocket服务器

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
"jcbowen/yiiswoole": "^1.3"
```

### 配置说明

在`console/config/main.php`的controllerMap中加入配置

（`SL_CONSOLE_RUNTIME`是我定义的runtime目录的常量，使用时记得替换掉）

1.3.0之前的配置方法
```
'websocket' => [
    'class'       => 'jcbowen\yiiswoole\websocket\console\controllers\WebSocketController',
    'serverClass' => 'jcbowen\yiiswoole\websocket\console\components\WebSocketServer',
    'host'        => '0.0.0.0',
    'port'        => 9410,
    'type'        => 'ws',
    'config'      => [// swoole4配置项
        'daemonize' => false,// 守护进程执行
        'pid_file'  => SL_CONSOLE_RUNTIME . '/logs/websocket.pid',
        'log_file'  => SL_CONSOLE_RUNTIME . '/logs/websocket.log',
        'log_level' => SWOOLE_LOG_ERROR,
    ],
],
```
1.3.0之后的配置方法(示例的是同时支持ws和wss的配置，使用wss时注意配置cert证书的路径)
```
'websockets' => [
            'class'       => 'jcbowen\yiiswoole\websocket\console\controllers\WebSocketsController',
            'serverClass' => 'jcbowen\yiiswoole\websocket\console\components\Server',
            'ports'       => [
                // 第一个为websocket主服务
                'ws'  => [
                    'host'   => '0.0.0.0',
                    'port'   => 9408,
                    'type'   => 'ws',
                    'config' => [// 标准的swoole4配置项都可以再此加入
                                 'daemonize'                => true,// 守护进程执行
                                 'heartbeat_check_interval' => 60, // 启用心跳检测，默认为false
                                 'heartbeat_idle_time'      => 60 * 2, // 连接最大允许空闲的时间，启用心跳检测的情况下，如未设置，默认未心跳检测的两倍
                                 'pid_file'                 => SL_CONSOLE_RUNTIME . '/logs/websocket.pid',
                                 'log_file'                 => SL_CONSOLE_RUNTIME . '/logs/websocket.log',
                                 'log_level'                => SWOOLE_LOG_ERROR,
                    ],
                ],
                'wss' => [
                    'host'   => '0.0.0.0',
                    'port'   => 9410,
                    'type'   => 'wss',
                    'config' => [// 标准的swoole4配置项都可以再此加入
                                 'open_http_protocol'      => true,
                                 'open_websocket_protocol' => true,
                                 'ssl_cert_file'           => __DIR__ . '/cert/fullchain.pem',
                                 'ssl_key_file'            => __DIR__ . '/cert/privkey.pem',
                    ],
                ]
            ],
        ],
```

### 使用

1.3.0以前的版本
```
# 启动 
php yii websocket/start
# 停止 
php yii websocket/stop
# 重启 
php yii websocket/restart
```
1.3.0及之后的版本
```
# 启动 
php yii websockets/start
# 停止 
php yii websockets/stop
# 重启 
php yii websockets/restart
```

### 运行说明

##### websocket客户端向服务器发送json字符串，如：

```
{"route": "site/test", "message": "这是一条来自websocket客户端的消息"}
（如果握手的时候，携带了目录路径，可以不用再携带route参数；仍然携带的话，以携带的为准）
```
##### 通过执行```\jcbowen\yiiswoole\websocket\console\componentsContext::getBG($_B, $_GPC);```方法，可以读取上下文中缓存的信息；
##### 其中server和frame会被缓存到```$_B```中；
##### 接收到的json会被转为数组后缓存到```$_GPC```中；
##### 携带的目录代表的是监听到动作后转发到哪个路由(由于通过console运行的进程，所以这里的路由指的是console里的路由)。
```
// 这里展示onMessage的源码，用来理解实现原理
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

```

##### 总结：jcbowen/yiiswoole插件会在websocket触发onmessage时，根据route调用对应的控制器方法，并将websocket服务和接收到数据分别存放到```$_B```与```$_GPC```变量中；

### 在控制器方法中使用
```
class SiteController extends Controller
{
    public function actionTest()
    {
        global $_B, $_GPC;

        // $_B['WebSocket']['on']可以判断是通过什么方式转发过来的

        $ws = $_B['WebSocket']['server'];
        $frame = $_B['WebSocket']['frame'];
        return $ws->push($frame->fd, $_GPC['message']);
    }
    
    public function actionIndex()
    {
        Context::getBG($_B, $_GPC);
        
        // 可以根据$_B['WebSocket']['on']判断是通过什么方式转发过来的
        // $_B['WebSocket']['on']的值有open/message/close

        $ws = $_B['WebSocket']['server'];
        $frame = $_B['WebSocket']['frame'];
        return $ws->push($frame->fd, $_GPC['message']);
    }
    
}
```
