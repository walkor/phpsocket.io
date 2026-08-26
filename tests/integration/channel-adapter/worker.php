<?php

use PHPSocketIO\ChannelAdapter;
use PHPSocketIO\SocketIO;
use Workerman\Worker;

require_once __DIR__ . '/../../../vendor/autoload.php';

$workerName = getenv('WORKER_NAME') ?: 'A';
$port = (int)(getenv('WORKER_PORT') ?: 2030);
$channelHost = getenv('CHANNEL_HOST') ?: '127.0.0.1';

// ChannelAdapter's constructor opens a real AsyncTcpConnection to the
// channel server, which needs Workerman's event loop already running --
// constructing SocketIO at top-level script scope (before Worker::runAll())
// fails. Deferring into a bootstrap worker's onWorkerStart is the pattern
// that actually works.
$bootstrap = new Worker();
$bootstrap->count = 1;
$bootstrap->onWorkerStart = function () use ($workerName, $port, $channelHost) {
    ChannelAdapter::$ip = $channelHost;
    ChannelAdapter::$port = 2206;

    $io = new SocketIO($port, ['adapter' => '\PHPSocketIO\ChannelAdapter']);
    // A Worker created this late (inside another worker's onWorkerStart)
    // misses Workerman's normal pre-fork listen() pass, so it never binds
    // its port unless done explicitly here.
    $io->worker->listen();

    $io->on('connection', function ($socket) use ($workerName) {
        $socket->emit('welcome', ['sid' => $socket->id, 'worker' => $workerName]);

        $socket->on('join-room', function ($room) use ($socket) {
            $socket->join($room);
        });

        $socket->on('ping-sid', function ($targetSid) use ($socket, $workerName) {
            $socket->to($targetSid)->emit('ping-received', [
                'from' => $socket->id,
                'worker' => $workerName,
            ]);
        });

        $socket->on('ping-room', function ($room) use ($socket, $workerName) {
            $socket->to($room)->emit('room-ping-received', [
                'from' => $socket->id,
                'worker' => $workerName,
            ]);
        });
    });
};

Worker::runAll();
