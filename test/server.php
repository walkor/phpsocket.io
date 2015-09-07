<?php
use Workerman\Worker;
use Workerman\Autoloader;
use \Engine\Engine;
include __DIR__ . '/../vendor/workerman/workerman/Autoloader.php';
spl_autoload_register(function($name){
    $path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    if(is_file($class_file = __DIR__ . "/../src/$path.php"))
    {
        require_once($class_file);
        if(class_exists($name, false))
        {
            return true;
        }
        echo $name."\n";
    }
    echo $class_file;
    return false;
});
class_alias('\Engine\Protocols\Http', "Protocols\\Http");
$io = new Worker('Http://0.0.0.0:8889');
$io->onMessage = 'test';
$o = new SocketIO();
$o->attach($io);
$o->on('connection', function($socket)use($o){
    $socket->on('chat message', function($msg)use($o,$socket)
    {
        $o->emit('chat message', $msg);
        $socket->emit('chat message', 'this is test');
        $socket->broadcast->emit('chat message', 'hello');
    });
});

Worker::runAll();
