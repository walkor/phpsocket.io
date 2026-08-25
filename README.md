# phpsocket.io

[![Packagist Version](https://img.shields.io/packagist/v/workerman/phpsocket.io)](https://packagist.org/packages/workerman/phpsocket.io)
[![Total Downloads](https://img.shields.io/packagist/dt/workerman/phpsocket.io)](https://packagist.org/packages/workerman/phpsocket.io)
[![Monthly Downloads](https://img.shields.io/packagist/dm/workerman/phpsocket.io)](https://packagist.org/packages/workerman/phpsocket.io)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-blue)](https://www.php.net)
[![Workerman](https://img.shields.io/badge/workerman-%3E%3D4.0%20%3C5.0-orange)](https://github.com/walkor/Workerman)
[![CI](https://github.com/walkor/phpsocket.io/actions/workflows/ci.yml/badge.svg)](https://github.com/walkor/phpsocket.io/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/workerman/phpsocket.io)](LICENSE)

A server-side PHP implementation of [socket.io](https://github.com/socketio/socket.io) based on [Workerman](https://github.com/walkor/Workerman).

> **Notice:** Only supports socket.io client >= v1.3.0 and <= v2.x (verified end-to-end against
> `socket.io-client@2.5.0`, the last 2.x release, over both polling and websocket transports).
> **socket.io v3 and v4 are not supported.**
> Requires PHP >= 7.4 and Workerman >= 4.0 < 5.0. **Workerman 5.x is not supported.**
> For the full server API, see [socket.io/docs/v2/server-api](https://socket.io/docs/v2/server-api/).
> Upgrading from v2.x? See [UPGRADING.md](UPGRADING.md) for what changed in v3.0.0.

## Table of contents

- [Install](#install)
- [Examples](#examples)
- [Run the examples](#run-the-examples)
- [Development](#development)
- [Contributing](#contributing)
- [Upgrading](UPGRADING.md)
- [License](#license)

## Install

```bash
composer require workerman/phpsocket.io
```

## Examples

### Simple chat

`start.php`

```php
<?php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

// Listen on port 2026 for socket.io clients
$io = new SocketIO(2026);

$io->on('connection', function ($socket) use ($io) {
    $socket->on('chat message', function ($msg) use ($io) {
        $io->emit('chat message', $msg);
    });
});

Worker::runAll();
```

### Another chat demo

Full source: [examples/chat/start_io.php](https://github.com/walkor/phpsocket.io/blob/master/examples/chat/start_io.php)

```php
<?php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(2026);

$io->on('connection', function ($socket) {
    $socket->addedUser = false;

    // When the client emits 'new message', this listens and executes
    $socket->on('new message', function ($data) use ($socket) {
        $socket->broadcast->emit('new message', [
            'username' => $socket->username,
            'message'  => $data,
        ]);
    });

    // When the client emits 'add user', this listens and executes
    $socket->on('add user', function ($username) use ($socket) {
        // Note: global variables are used here for simplicity (demo only)
        global $usernames, $numUsers;

        $socket->username = $username;
        $usernames[$username] = $username;
        ++$numUsers;

        $socket->addedUser = true;
        $socket->emit('login', ['numUsers' => $numUsers]);

        $socket->broadcast->emit('user joined', [
            'username' => $socket->username,
            'numUsers' => $numUsers,
        ]);
    });

    // When the client emits 'typing', broadcast it to others
    $socket->on('typing', function () use ($socket) {
        $socket->broadcast->emit('typing', ['username' => $socket->username]);
    });

    // When the client emits 'stop typing', broadcast it to others
    $socket->on('stop typing', function () use ($socket) {
        $socket->broadcast->emit('stop typing', ['username' => $socket->username]);
    });

    // When the user disconnects
    $socket->on('disconnect', function () use ($socket) {
        global $usernames, $numUsers;

        if ($socket->addedUser) {
            unset($usernames[$socket->username]);
            --$numUsers;

            $socket->broadcast->emit('user left', [
                'username' => $socket->username,
                'numUsers' => $numUsers,
            ]);
        }
    });
});

Worker::runAll();
```

### Enable SSL (HTTPS)

> Requires phpsocket.io >= 1.1.1 and workerman >= 3.3.7

`start.php`

```php
<?php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

$context = [
    'ssl' => [
        'local_cert'  => '/your/path/of/server.pem',
        'local_pk'    => '/your/path/of/server.key',
        'verify_peer' => false,
    ],
];

$io = new SocketIO(2026, $context);

$io->on('connection', function ($socket) {
    echo "New connection: {$socket->id}\n";
});

Worker::runAll();
```

### Acknowledgement callback

```php
<?php

use Workerman\Worker;
use PHPSocketIO\SocketIO;

require_once __DIR__ . '/vendor/autoload.php';

$io = new SocketIO(2026);

$io->on('connection', function ($socket) {
    $socket->on('message with ack', function ($data, $callback) {
        if ($callback && is_callable($callback)) {
            $callback(0);
        }
    });
});

Worker::runAll();
```

## Run the examples

### Chat ([examples/chat](examples/chat))

A full chat room demo -- online user list, typing indicators, message timestamps, and
color-coded server-side logging.

```bash
docker compose up --build         # or: docker compose up --build phpsocketio
```

Then open [http://localhost:2027](http://localhost:2027) (Socket.IO on port `2026`).

Without Docker:

```bash
cd examples/chat
php start.php start       # debug mode
php start.php start -d    # daemon mode
php start.php stop        # stop
php start.php status      # status
```

### Rooms ([examples/rooms](examples/rooms))

A minimal demo of `socket->join()`/`leave()`/`to(room)->emit()` -- join a room, message
everyone else in it, leave, and watch the member list update live.

```bash
docker compose up --build rooms
```

Then open [http://localhost:2031](http://localhost:2031) (Socket.IO on port `2030`) in two
browser tabs.

Without Docker:

```bash
cd examples/rooms
php start.php start
```

### Acknowledgements ([examples/ack](examples/ack))

Both directions of `emit(event, data, callback)` -- client asking the server to ack, and
the server asking a specific client to ack back.

```bash
docker compose up --build ack
```

Then open [http://localhost:2033](http://localhost:2033) (Socket.IO on port `2032`).

Without Docker:

```bash
cd examples/ack
php start.php start
```

### Namespaces ([examples/namespaces](examples/namespaces))

Two independent channels (`/public`, `/admin`) on the same server via `io->of()` -- messages
in one never reach clients connected to the other.

```bash
docker compose up --build namespaces
```

Then open [http://localhost:2035](http://localhost:2035) (Socket.IO on port `2034`).

Without Docker:

```bash
cd examples/namespaces
php start.php start
```

## Development

This project uses GitHub Actions to run on every pull request and push to `master`:

- [PHP_CodeSniffer](https://github.com/squizlabs/PHP_CodeSniffer), ensuring the codebase follows the coding standard defined in `phpcs.xml`.
- The PHPUnit test suite, across PHP 7.4 through 8.5.

To run locally:

```bash
# Without Docker
composer install
composer lint   # vendor/bin/phpcs src/
composer test   # vendor/bin/phpunit

# With Docker
docker compose --profile tools run --rm phpcs
docker compose --profile tools run --rm phpunit
```

## Contributing

Contributions are welcome! See [CONTRIBUTING.md](CONTRIBUTING.md) for how to set up the project,
run checks locally, and submit a pull request.

## License

[MIT](LICENSE)