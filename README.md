# phpsocket.io
An alternative implementation of [socket.io](https://github.com/socketio/socket.io) in PHP based on [Workerman](https://github.com/walkor/Workerman).

# Install
composer require workerman/phpsocket.io

# Examples
## Simple chat
```php
use PHPSocketIO\SocketIO;

// listen port 2021 for socket.io client
$io = new SocketIO(2021);
$io->on('connection', function($socket)use($io){
  $socket->on('chat message', function($msg)use($io){
    $io->emit('chat message', $msg);
  });
});
```

## Another chat demo

https://github.com/walkor/phpsocket.io/blob/master/examples/chat/server.php
```php
use PHPSocketIO\SocketIO;

// listen port 2020 for socket.io client
$io = new SocketIO(2020);
$io->on('connection', function($socket){
    $socket->addedUser = false;
    // when the client emits 'new message', this listens and executes
    $socket->on('new message', function ($data)use($socket){
        // we tell the client to execute 'new message'
        $socket->broadcast->emit('new message', array(
            'username'=> $socket->username,
            'message'=> $data
        ));
    });
    // when the client emits 'add user', this listens and executes
    $socket->on('add user', function ($username) use($socket){
        global $usernames, $numUsers;
        // we store the username in the socket session for this client
        $socket->username = $username;
        // add the client's username to the global list
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
    // when the client emits 'typing', we broadcast it to others
    $socket->on('typing', function () use($socket) {
        $socket->broadcast->emit('typing', array(
            'username' => $socket->username
        ));
    });
    // when the client emits 'stop typing', we broadcast it to others
    $socket->on('stop typing', function () use($socket) {
        $socket->broadcast->emit('stop typing', array(
            'username' => $socket->username
        ));
    });
    // when the user disconnects.. perform this
    $socket->on('disconnect', function () use($socket) {
        global $usernames, $numUsers;
        // remove the username from global usernames list
        if($socket->addedUser) {
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
```
# Livedemo
[chat demo](http://www.workerman.net/demos/phpsocketio-chat/)

# Run chat example
cd examples/chat

## Start
```php server.php start``` for debug mode

```php server.php start -d ``` for daemon mode

## Stop
```php server.php stop```

## Status
```php server.php status```

# License
MIT
