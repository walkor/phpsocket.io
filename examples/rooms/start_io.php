<?php
use Workerman\Worker;
use PHPSocketIO\SocketIO;

// composer autoload
require_once join(DIRECTORY_SEPARATOR, array(__DIR__, '..', '..', 'vendor', 'autoload.php'));

function roomsLog(string $level, string $message): void
{
    $time = date('Y-m-d H:i:s');
    $levels = ['INFO' => "\033[32m", 'DISCONNECT' => "\033[33m", 'ERROR' => "\033[31m"];
    $color  = $levels[$level] ?? "\033[37m";
    echo "{$color}[{$time}] [{$level}] {$message}\033[0m\n";
}

$io = new SocketIO(2030);

// room name => [socket id => username]
$roomMembers = [];

function membersOf(string $room): array
{
    global $roomMembers;
    return array_values($roomMembers[$room] ?? []);
}

$io->on('connection', function ($socket) use (&$roomMembers) {
    $socket->currentRoom = null;
    $socket->username = null;

    roomsLog('INFO', "New connection | sid: {$socket->id}");

    $socket->on('join room', function ($data) use ($socket, &$roomMembers) {
        $room = trim($data['room'] ?? '');
        $username = trim($data['username'] ?? '');

        if ($room === '' || $username === '') {
            $socket->emit('room error', ['message' => 'Room and username are required.']);
            return;
        }

        // Leave whatever room this socket was already in, if any.
        if ($socket->currentRoom !== null) {
            $previousRoom = $socket->currentRoom;
            $socket->leave($previousRoom);
            unset($roomMembers[$previousRoom][$socket->id]);
            $socket->to($previousRoom)->emit('member left', [
                'room' => $previousRoom,
                'username' => $socket->username,
                'members' => membersOf($previousRoom),
            ]);
        }

        $socket->join($room);
        $socket->currentRoom = $room;
        $socket->username = $username;
        $roomMembers[$room][$socket->id] = $username;

        roomsLog('INFO', "[{$username}] joined room \"{$room}\" | members: " . count($roomMembers[$room]));

        $socket->emit('room joined', [
            'room' => $room,
            'username' => $username,
            'members' => membersOf($room),
        ]);

        $socket->to($room)->emit('member joined', [
            'room' => $room,
            'username' => $username,
            'members' => membersOf($room),
        ]);
    });

    $socket->on('leave room', function () use ($socket, &$roomMembers) {
        $room = $socket->currentRoom;
        if ($room === null) {
            return;
        }

        $socket->leave($room);
        unset($roomMembers[$room][$socket->id]);
        $socket->currentRoom = null;

        roomsLog('INFO', "[{$socket->username}] left room \"{$room}\" | members: " . count($roomMembers[$room] ?? []));

        $socket->to($room)->emit('member left', [
            'room' => $room,
            'username' => $socket->username,
            'members' => membersOf($room),
        ]);

        $socket->emit('room left', ['room' => $room]);
    });

    $socket->on('room message', function ($message) use ($socket) {
        $room = $socket->currentRoom;
        if ($room === null) {
            $socket->emit('room error', ['message' => 'Join a room before sending messages.']);
            return;
        }

        roomsLog('INFO', "[{$room}] {$socket->username}: {$message}");

        $socket->to($room)->emit('room message', [
            'room' => $room,
            'username' => $socket->username,
            'message' => $message,
        ]);
    });

    $socket->on('disconnect', function () use ($socket, &$roomMembers) {
        $room = $socket->currentRoom;
        if ($room === null) {
            roomsLog('DISCONNECT', "Connection closed before joining a room | sid: {$socket->id}");
            return;
        }

        unset($roomMembers[$room][$socket->id]);
        roomsLog('DISCONNECT', "[{$socket->username}] disconnected from \"{$room}\" | members: " . count($roomMembers[$room] ?? []));

        $socket->to($room)->emit('member left', [
            'room' => $room,
            'username' => $socket->username,
            'members' => membersOf($room),
        ]);
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
