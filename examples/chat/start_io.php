<?php
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// composer autoload
require_once join(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'vendor', 'autoload.php'));

function chatLog(string $level, string $message): void
{
    $time = date('Y-m-d H:i:s');
    $levels = ['INFO' => "\033[32m", 'DISCONNECT' => "\033[33m", 'ERROR' => "\033[31m"];
    $color  = $levels[$level] ?? "\033[37m";
    echo "{$color}[{$time}] [{$level}] {$message}\033[0m\n";
}

$io = new SocketIO(2026);

$io->on('connection', function ($socket) {
    $socket->addedUser = false;

    $remoteAddress = $socket->conn->remoteAddress ?? 'unknown';
    chatLog('INFO', "New connection from {$remoteAddress} | sid: {$socket->id}");

    $socket->on('new message', function ($data) use ($socket) {
        chatLog('INFO', "Message from [{$socket->username}]: {$data}");
        $socket->broadcast->emit('new message', [
            'username' => $socket->username,
            'message'  => $data,
        ]);
    });

    $socket->on('add user', function ($username) use ($socket) {
        if ($socket->addedUser) return;

        global $usernames, $numUsers;

        $socket->username  = $username;
        $usernames[$username] = $username;
        ++$numUsers;
        $socket->addedUser = true;

        chatLog('INFO', "User joined: [{$username}] | online: {$numUsers}");

        $socket->emit('login', [
            'numUsers'  => $numUsers,
            'usernames' => array_values($usernames),
        ]);

        $socket->broadcast->emit('user joined', [
            'username' => $socket->username,
            'numUsers' => $numUsers,
        ]);
    });

    $socket->on('typing', function () use ($socket) {
        $socket->broadcast->emit('typing', ['username' => $socket->username]);
    });

    $socket->on('stop typing', function () use ($socket) {
        $socket->broadcast->emit('stop typing', ['username' => $socket->username]);
    });

    $socket->on('disconnect', function () use ($socket) {
        global $usernames, $numUsers;

        if ($socket->addedUser) {
            unset($usernames[$socket->username]);
            --$numUsers;

            chatLog('DISCONNECT', "User left: [{$socket->username}] | online: {$numUsers}");

            $socket->broadcast->emit('user left', [
                'username' => $socket->username,
                'numUsers' => $numUsers,
            ]);
        } else {
            $remoteAddress = $socket->conn->remoteAddress ?? 'unknown';
            chatLog('DISCONNECT', "Connection closed before login | {$remoteAddress}");
        }
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}