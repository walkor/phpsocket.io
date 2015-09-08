<?php
use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Autoloader;
use Engine\Engine;
//include __DIR__ . '/vendor/workerman/workerman/Autoloader.php';
include __DIR__ . '/vendor/autoload.php';
spl_autoload_register(function($name){
    $path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    if(is_file($class_file = __DIR__ . "/../../src/$path.php"))
    {
        require_once($class_file);
        if(class_exists($name, false))
        {
            return true;
        }
    }
    return false;
});
class_alias('\Engine\Protocols\Http', "Protocols\\Http");
$worker = new Worker('Http://0.0.0.0:2020');
$worker->onMessage = 'test';
$io = new SocketIO();
$io->attach($worker);
$io->on('connection', function($socket){
    $socket->on('chat message', function($msg)use($socket)
    {
        //$o->emit('chat message', $msg);
        //$socket->emit('chat message', 'this is test');
        //$socket->broadcast->emit('chat message', 'hello');
        
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
});


Worker::runAll();
