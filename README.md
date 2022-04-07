# phpsocket.io
A server side alternative implementation of [socket.io](https://github.com/socketio/socket.io) in PHP based on [Workerman](https://github.com/walkor/Workerman).<br>

# Notice
Only support socket.io >= v1.3.0 and <= v2.x <br>
This project is just translate socket.io by [workerman](https://github.com/walkor/Workerman).<br>
More api just see [https://socket.io/docs/v2/server-api/](https://socket.io/docs/v2/server-api/)

# Install
composer require workerman/phpsocket.io

# Examples
## Simple chat
start.php
```php

use Workerman\Worker;
use PHPSocketIO\SocketIO;
require_once __DIR__ . '/vendor/autoload.php';

// Listen port 2021 for socket.io client
$io = new SocketIO(2021);
$io->on('connection', function ($socket) use ($io) {
    $socket->on('chat message', function ($msg) use ($io) {
        $io->emit('chat message', $msg);
    });
});

Worker::runAll();
```

## Another chat demo

https://github.com/walkor/phpsocket.io/blob/master/examples/chat/start_io.php
```php

use Workerman\Worker;
use PHPSocketIO\SocketIO;
require_once __DIR__ . '/vendor/autoload.php';

// Listen port 2020 for socket.io client
$io = new SocketIO(2020);
$io->on('connection', function ($socket) {
    $socket->addedUser = false;

    // When the client emits 'new message', this listens and executes
    $socket->on('new message', function ($data) use ($socket) {
        // We tell the client to execute 'new message'
        $socket->broadcast->emit('new message', array(
            'username' => $socket->username,
            'message' => $data
        ));
    });

    // When the client emits 'add user', this listens and executes
    $socket->on('add user', function ($username) use ($socket) {
        global $usernames, $numUsers;

        // We store the username in the socket session for this client
        $socket->username = $username;
        // Add the client's username to the global list
        $usernames[$username] = $username;
        ++$numUsers;

        $socket->addedUser = true;
        $socket->emit('login', array( 
            'numUsers' => $numUsers
        ));

        // echo globally (all clients) that a person has connected
        $socket->broadcast->emit('user joined', array(
            'username' => $socket->username,
            'numUsers' => $numUsers
        ));
    });

    // When the client emits 'typing', we broadcast it to others
    $socket->on('typing', function () use ($socket) {
        $socket->broadcast->emit('typing', array(
            'username' => $socket->username
        ));
    });

    // When the client emits 'stop typing', we broadcast it to others
    $socket->on('stop typing', function () use ($socket) {
        $socket->broadcast->emit('stop typing', array(
            'username' => $socket->username
        ));
    });

    // When the user disconnects, perform this
    $socket->on('disconnect', function () use ($socket) {
        global $usernames, $numUsers;

        // Remove the username from global usernames list
        if ($socket->addedUser) {
            unset($usernames[$socket->username]);
            --$numUsers;

            // echo globally that this client has left
            $socket->broadcast->emit('user left', array(
               'username' => $socket->username,
               'numUsers' => $numUsers
            ));
        }
   });
});

Worker::runAll();
```

## Enable SSL for https 
**```(phpsocket.io>=1.1.1 && workerman>=3.3.7 required)```**

start.php
```php
<?php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

// SSL context
$context = array(
    'ssl' => array(
        'local_cert'  => '/your/path/of/server.pem',
        'local_pk'    => '/your/path/of/server.key',
        'verify_peer' => false
    )
);
$io = new SocketIO(2021, $context);

$io->on('connection', function ($connection) use ($io) {
    echo "New connection coming\n";
});

Worker::runAll();
```

## Acknowledgement callback
```php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(2021);

$io->on('connection', function ($connection) use ($io) {
    $socket->on('message with ack', function ($data, $callback) use ($socket, $io) {
        // acknowledgement callback
        if ($callback && is_callable($callback)) {
            $callback(0);
        }
    });
});

Worker::runAll();
```

# 手册
[中文手册](https://github.com/walkor/phpsocket.io/tree/master/docs/zh)

# Livedemo
[chat demo](http://demos.workerman.net/phpsocketio-chat/)

# Run chat example
cd examples/chat

## Start
```php start.php start``` for debug mode

```php start.php start -d ``` for daemon mode

## Stop
```php start.php stop```

## Status
```php start.php status```

# License
MIT
