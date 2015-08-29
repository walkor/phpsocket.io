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
    }
    echo $class_file;
    return false;
});
class_alias('\Engine\Protocols\Http2', "Protocols\\Http2");
$io = new Worker('Http2://0.0.0.0:8888');
$io->onMessage = 'test';
/*$io->onConnect = function($connection)
{
    $connection->onRequest = function($req, $res)
    {
        $req->onEnd = function($req){echo "end\n";};
        $req->onData = function($req, $data){echo $data;};
        $res->write('ok');
        $res->write(var_export($req->headers, true));
        $res->write('yeeh');
        $res->end();
    };
};
*/
//$engine = new Engine();
//$engine->attach($io);

$o = new SocketIO();
$o->attach($io);

Worker::runAll();
