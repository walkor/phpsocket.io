<?php
use Workerman\Worker;
include __DIR__ . '/../vendor/workerman/workerman/Autoloader.php';
include __DIR__ . '/../src/Engine/Protocols/Http2.php';

$io = new Worker('Http2://0.0.0.0:8888');
$io->onMessage = 'test';
$io->onConnect = function($connection)
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

Worker::runAll();
