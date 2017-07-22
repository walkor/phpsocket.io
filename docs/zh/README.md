## 安装
请使用composer集成phpsocket.io。

脚本中引用vendor中的autoload.php实现SocketIO相关类的加载。例如
```php
require_once '/你的vendor路径/autoload.php';
```

## 服务端和客户端连接
**创建一个SocketIO服务端**
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// 创建socket.io服务端，监听2021端口
$io = new SocketIO(3120);
// 当有客户端连接时打印一行文字
$io->on('connection', function($socket)use($io){
  echo "new connection coming\n";
});

Worker::runAll();
```
**客户端**
```javascript
<script src='https://cdn.bootcss.com/socket.io/2.0.3/socket.io.js'></script>
<script>
// 如果服务端不在本机，请把127.0.0.1改成服务端ip
var socket = io('http://127.0.0.1:3120');
// 当连接服务端成功时触发connect默认事件
socket.on('connect', function(){
    console.log('connect success');
});
</script>
```

## 自定义事件
socket.io主要是通过事件来进行通讯交互的。

socket连接除了自带的connect，message，disconnect三个事件以外，在服务端和客户端开发者可以自定义其它事件。

服务端和客户端都通过emit方法触发对端的事件。

例如下面的代码在服务端定义了一个```chat message```事件，事件参数为```$msg```。
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use PHPSocketIO\SocketIO;

$io = new SocketIO(3120);
// 当有客户端连接时
$io->on('connection', function($socket)use($io){
  // 定义chat message事件回调函数
  $socket->on('chat message', function($msg)use($io){
    // 触发所有客户端定义的chat message from server事件
    $io->emit('chat message from server', $msg);
  });
});
Worker::runAll();
```

客户端通过下面的方法触发服务端的chat message事件。
```javascript
<script src='//cdn.bootcss.com/socket.io/1.3.7/socket.io.js'></script>
<script>
// 连接服务端
var socket = io('http://127.0.0.1:3120');
// 触发服务端的chat message事件
socket.emit('chat message', '这个是消息内容...');
// 服务端通过emit('chat message from server', $msg)触发客户端的chat message from server事件
socket.on('chat message from server', function(msg){
    console.log('get message:' + msg + ' from server');
});
</script>
```

## workerStart事件
phpsocket.io提供了workerStart事件回调，也就是当进程启动后准备好接受客户端链接时触发的回调。
一个进程生命周期只会触发一次。可以在这里设置一些全局的事情，比如开一个新的Worker端口等等。
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use PHPSocketIO\SocketIO;

$io = new SocketIO(9120);

// 监听一个http端口，通过http协议访问这个端口可以向所有客户端推送数据(url类似http://ip:9191?msg=xxxx)
$io->on('workerStart', function()use($io) {
    $inner_http_worker = new Worker('http://0.0.0.0:9191');
    $inner_http_worker->onMessage = function($http_connection, $data)use($io){
        if(!isset($_GET['msg'])) {
            return $http_connection->send('fail, $_GET["msg"] not found');
        }
        $io->emit('chat message', $_GET['msg']);
        $http_connection->send('ok');
    };
    $inner_http_worker->listen();
});

// 当有客户端连接时
$io->on('connection', function($socket)use($io){
  // 定义chat message事件回调函数
  $socket->on('chat message', function($msg)use($io){
    // 触发所有客户端定义的chat message from server事件
    $io->emit('chat message from server', $msg);
  });
});

Worker::runAll();
```
phpsocket.io启动后开内部http端口通过phpsocket.io向客户端推送数据参考 [web-msg-sender](http://www.workerman.net/web-sender)。

## 分组
socket.io提供分组功能，允许向某个分组发送事件，例如向某个房间广播数据。

1、加入分组（一个连接可以加入多个分组）
```php
$socket->join('group name');
```
2、离开分组（连接断开时会自动从分组中离开）
```php
$socket->leave('group name');
```

## 向客户端发送事件的各种方法
$io是SocketIO对象。$socket是客户端连接

$data可以是数字和字符串，也可以是数组。当$data是数组时，客户端会自动转换为javascript对象。

同理如果客户端向服务端emit某个事件传递的是一个javascript对象，在服务端接收时会自动转换为php数组。

1、向当前客户端发送事件
```php
$socket->emit('event name', $data);
```
2、向所有客户端发送事件
```php
$io->emit('event name', $data);
```
3、向所有客户端发送事件，但不包括当前连接。
```php
$socket->broadcast->emit('event name', $data);
```

4、向某个分组的所有客户端发送事件
```php
$io->to('group name')->emit('event name', $data);
```

## 获取客户端ip
```php
$io->on('connection', function($socket)use($io){
        var_dump($socket->conn->remoteAddress);
});
```

## 关闭链接
```php
$socket->disconnect();
```

## 支持SSL(https wss)
SSL 要求workerman>=3.3.7 phpsocket.io>=1.1.1

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// 传入ssl选项，包含证书的路径
$context = array(
    'ssl' => array(
        'local_cert'  => '/your/path/of/server.pem',
        'local_pk'    => '/your/path/of/server.key',
        'verify_peer' => false,
    )
);
$io = new SocketIO(2021, $context);

$io->on('connection', function($socket)use($io){
  echo "new connection coming\n";
});

Worker::runAll();
```
**注意：**<br>
1、证书是要验证域名的，所以客户端链接时要指定域名才能顺利的建立链接。<br>
2、客户端连接时不能再用http方式，要改成https类似下面这样。
```javascript
<script>
var socket = io('https://yoursite.com:3120');
//.....
</script>
```
