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