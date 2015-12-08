### 创建一个SocketIO服务端
```php
use PHPSocketIO\SocketIO;
// 创建socket.io服务端，监听2021端口
$io = new SocketIO(2021);
// 当有客户端连接时打印一行文字
$io->on('connection', function($socket)use($io){
  echo "new connection coming\n";
});
```

### 自定义事件
```php
use PHPSocketIO\SocketIO;
$io = new SocketIO(2021);
// 当有客户端连接时
$io->on('connection', function($socket)use($io){
  // 定义chat message事件回调函数
  $socket->on('chat message', function($msg)use($io){
    // 给所有的客户端发送chat message事件
    $io->emit('chat message', $msg);
  });
});
```


