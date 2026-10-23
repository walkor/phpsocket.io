<?php

use PHPSocketIO\ChannelAdapter;
use PHPSocketIO\SocketIO;
use Workerman\Worker;

require_once __DIR__ . '/../../../vendor/autoload.php';

$workerName = getenv('WORKER_NAME') ?: 'A';
$port = (int)(getenv('WORKER_PORT') ?: 2030);
$channelHost = getenv('CHANNEL_HOST') ?: '127.0.0.1';

ChannelAdapter::$ip = $channelHost;
ChannelAdapter::$port = 2206;

$io = new SocketIO($port);
// The documented setup: ChannelAdapter needs the event loop, so it's set per worker process.
$io->on('workerStart', function () use ($io) {
    $io->adapter(ChannelAdapter::class);
});

// Same handlers on "/" and "/chat": declaring a second namespace used to
// break cross-worker delivery in both of them.
foreach (['/', '/chat'] as $name) {
    $nsp = $io->of($name);
    $nsp->on('connection', function ($socket) use ($nsp, $workerName) {
        $socket->emit('welcome', ['sid' => $socket->id, 'worker' => $workerName]);

        $socket->on('join-room', function ($room) use ($socket) {
            $socket->join($room);
        });

        $socket->on('leave-room', function ($room) use ($socket) {
            $socket->leave($room);
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

        $socket->on('ping-all', function () use ($nsp, $workerName) {
            $nsp->emit('all-ping-received', ['nsp' => $nsp->name, 'worker' => $workerName]);
        });
    });
}

Worker::runAll();
