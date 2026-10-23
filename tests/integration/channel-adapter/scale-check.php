<?php

// Simulates N sockets each auto-joining their own sid-room against a real
// ChannelAdapter + real workerman/channel server, then inspects how many
// channels \Channel\Client actually subscribed to. The bug in issue #303
// is specifically about that subscribed-channel count growing per
// connection; this measures it directly instead of needing N real sockets.

use PHPSocketIO\ChannelAdapter;
use PHPSocketIO\SocketIO;
use Workerman\Worker;

require_once __DIR__ . '/../../../vendor/autoload.php';

$channelHost = getenv('CHANNEL_HOST') ?: '127.0.0.1';
$count = (int)(getenv('SCALE_COUNT') ?: 10000);

$bootstrap = new Worker();
$bootstrap->count = 1;
$bootstrap->onWorkerStart = function () use ($channelHost, $count) {
    ChannelAdapter::$ip = $channelHost;
    ChannelAdapter::$port = 2206;

    $io = new SocketIO(null, ['adapter' => '\PHPSocketIO\ChannelAdapter']);
    $adapter = $io->sockets->adapter;

    for ($i = 0; $i < $count; $i++) {
        $sid = bin2hex(pack('d', microtime(true)) . pack('N', $i));
        $adapter->add($sid, $sid);
    }

    $ref = new ReflectionProperty('Channel\Client', '_events');
    $ref->setAccessible(true);
    $subscribed = $ref->getValue();

    echo "Simulated {$count} sid-room joins.\n";
    echo 'Subscribed channel count: ' . count($subscribed) . "\n";

    posix_kill(posix_getpid(), SIGTERM);
};

Worker::runAll();
