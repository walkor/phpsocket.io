<?php
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// composer autoload
require_once join(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'vendor', 'autoload.php'));

function nsLog(string $level, string $message): void
{
    $time = date('Y-m-d H:i:s');
    $levels = ['INFO' => "\033[32m", 'DISCONNECT' => "\033[33m", 'ERROR' => "\033[31m"];
    $color  = $levels[$level] ?? "\033[37m";
    echo "{$color}[{$time}] [{$level}] {$message}\033[0m\n";
}

$io = new SocketIO(2034);

// Each namespace is a fully independent communication channel: sockets
// connected to /public never see events emitted in /admin, and vice versa,
// even though both live on the very same server/port.
function wireNamespace(string $path, string $label): void
{
    global $io;
    $nsp = $io->of($path);
    $online = 0;

    $nsp->on('connection', function ($socket) use ($label, &$online) {
        $online++;
        nsLog('INFO', "[{$label}] connected | sid: {$socket->id} | online: {$online}");

        $socket->emit('welcome', ['namespace' => $label, 'online' => $online]);
        $socket->broadcast->emit('presence', ['namespace' => $label, 'online' => $online]);

        $socket->on('message', function ($text) use ($socket, $label) {
            nsLog('INFO', "[{$label}] message from {$socket->id}: {$text}");
            $socket->broadcast->emit('message', ['namespace' => $label, 'text' => $text]);
        });

        $socket->on('disconnect', function () use ($label, &$online) {
            $online--;
            nsLog('DISCONNECT', "[{$label}] disconnected | online: {$online}");
        });
    });
}

wireNamespace('/public', 'public');
wireNamespace('/admin', 'admin');

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
