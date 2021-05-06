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
"jcbowen/yiiswoole": "^1.0"
```

### 配置说明

在`console/config/main.php`的controllerMap中加入配置

（`SL_CONSOLE_RUNTIME`是我定义的runtime目录的常量，使用时记得替换掉）

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
```
##### websocket服务器接消息时，会将server和frame，存放到局部变量```$_B```中；
##### 同时将接收到的json转为数组，存放到局部变量```$_GPC```中；
##### 接着就根据route，调用console里controllers目录中的控制器方法。
```
public function onMessage($server, $frame)
{
    global $_GPC, $_B;
    $_B['WebSocket'] = ['server' => $server, 'frame' => $frame];
    $_GPC = ArrayHelper::merge($_GPC, (array)@json_decode($frame->data, true));

    $route = $_GPC['route'];
    unset($_GPC['route']);

    if (empty($route)) return $server->push($frame->fd, stripslashes(json_encode([
        'code' => 211,
        'msg'  => '路由错误'
    ], JSON_UNESCAPED_UNICODE)));

    try {
        return Yii::$app->runAction($route);
    } catch (Exception $e) {
        Yii::info($e);
        echo($e->getMessage());
        return false;
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

        $ws = $_B['WebSocket'];
        $frame = $_GPC['WS_frame'];
        return $ws->push($frame->fd, 'U Got Me');
    }
}
```
