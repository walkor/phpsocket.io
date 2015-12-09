## 安装
请使用composer集成phpsocket.io。

脚本中引用vendor中的autoload.php实现SocketIO相关类的加载。例如
```php
require_once '/你的vendor路径/autoload.php';
```
下面服务端的代码略去了这段代码。

### 服务端和客户端连接
创建一个SocketIO服务端
```php
use PHPSocketIO\SocketIO;
// 创建socket.io服务端，监听2021端口
$io = new SocketIO(2021);
// 当有客户端连接时打印一行文字
$io->on('connection', function($connection)use($io){
  echo "new connection coming\n";
});
```
客户端
```javascript
<script src='//cdn.bootcss.com/socket.io/1.3.7/socket.io.js'></script>
<script>
// 如果服务端不在本机，请把127.0.0.1改成服务端ip
var socket = io('http://127.0.0.1:3120');
// 当连接服务端成功时触发connect默认事件
socket.on('connect', function(){
    console.log('connect success');
});
</script>
```

### 自定义事件
socket.io主要是通过事件来进行通讯交互的。

除了自带的connect，message，disconnect三个事件以外，在服务端和客户端用户可以自定义事件。

服务端和客户端都通过emit方法触发对端的事件。

例如下面的代码在服务端定义了一个```chat message```事件，事件参数为```$msg```。
```php
use PHPSocketIO\SocketIO;
$io = new SocketIO(2021);
// 当有客户端连接时
$io->on('connection', function($connection)use($io){
  // 定义chat message事件回调函数
  $connection->on('chat message', function($msg)use($io){
    // 触发所有客户端定义的chat message from server事件
    $io->emit('chat message from server', $msg);
  });
});
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

### 分组
socket.io提供分组功能，允许向某个分组发送事件，例如向某个房间广播数据。

1、加入分组（一个连接可以加入多个分组）
```php
$connection->join('group name');
```
2、离开分组（连接断开时会自动从分组中离开）
```php
$connection->leave('group name');
```

## 向客户端发送事件的各种方法
$io是SocketIO对象。$connection是客户端连接

$data可以是数字和字符串，也可以是数组。当$data是数组时，客户端会自动转换为javascript对象。

同理如果客户端向服务端emit某个事件传递的是一个javascript对象，在服务端接收时会自动转换为php数组。

1、向当前客户端发送事件
```php
$connection->emit('event name', $data);
```
2、向所有客户端发送事件
```php
$io->emit('event name', $data);
```
3、向所有客户端发送事件，但不包括当前连接。
```php
$connection->sockets->emit('event name', $data);
```
4、向某个分组的所有客户端发送事件
```php
$io->to('group name')->emit('event name', $data);
```
