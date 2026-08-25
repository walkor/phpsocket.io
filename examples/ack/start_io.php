<?php
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// composer autoload
require_once join(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'vendor', 'autoload.php'));

function ackLog(string $level, string $message): void
{
    $time = date('Y-m-d H:i:s');
    $levels = ['INFO' => "\033[32m", 'DISCONNECT' => "\033[33m", 'ERROR' => "\033[31m"];
    $color  = $levels[$level] ?? "\033[37m";
    echo "{$color}[{$time}] [{$level}] {$message}\033[0m\n";
}

function nowMs(): int
{
    return (int) round(microtime(true) * 1000);
}

$io = new SocketIO(2032);

$io->on('connection', function ($socket) {
    ackLog('INFO', "New connection | sid: {$socket->id}");

    // --- Client -> server ack ---
    // The client calls socket.emit('c2s ping', clientTime, callback). Because it
    // passed a callback, the library appends an extra $ack argument (a
    // closure) to this listener's arguments -- calling it sends an ack
    // packet back to that same client with whatever we pass it.
    $socket->on('c2s ping', function ($clientTime, $ack) use ($socket) {
        ackLog('INFO', "[client->server] ping from {$socket->id} (clientTime={$clientTime})");
        if (is_callable($ack)) {
            $ack(['serverTime' => nowMs()]);
        }
    });

    // --- Server -> client ack ---
    // The client asks us (via a plain, ack-less event) to ping *it* and wait
    // for a reply. We emit with a trailing callback of our own; the client's
    // listener receives that callback and is expected to call it back.
    $socket->on('request server ping', function () use ($socket) {
        $serverSentAt = nowMs();
        ackLog('INFO', "[server->client] pinging {$socket->id}");

        // The client's ack callback args always arrive here as a single
        // array (one entry per argument the client's ack() call was given),
        // unlike regular event listeners, which get each argument spread
        // into its own parameter.
        $socket->emit('s2c ping', $serverSentAt, function ($ackArgs) use ($socket, $serverSentAt) {
            $clientAckTime = $ackArgs[0] ?? null;
            $roundTripMs = nowMs() - $serverSentAt;
            ackLog('INFO', "[server->client] {$socket->id} acked in {$roundTripMs}ms");

            $socket->emit('s2c ping result', [
                'serverSentAt' => $serverSentAt,
                'clientAckTime' => $clientAckTime,
                'roundTripMs' => $roundTripMs,
            ]);
        });
    });

    $socket->on('disconnect', function () use ($socket) {
        ackLog('DISCONNECT', "Connection closed | sid: {$socket->id}");
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
