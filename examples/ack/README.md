# Acknowledgements Example

A minimal demo of Socket.IO acknowledgements in both directions:

- **Client &rarr; Server ack**: the client calls `socket.emit('ping', data, callback)`.
  On the server, `$socket->on('ping', function ($data, $ack) { $ack($result); })` --
  the library appends the ack closure as an extra trailing argument whenever the
  incoming packet carries an id.
- **Server &rarr; Client ack**: the server calls
  `$socket->emit('event', $data, function ($clientResponse) { ... })`. The client
  receives it as a normal event whose last argument is the ack callback --
  calling it sends the reply back.

## Ports

| Service | Port |
|---|---|
| Web (demo UI) | `2033` |
| Socket.IO | `2032` |

## Run with Docker

```bash
docker compose up --build ack
```

Then open [http://localhost:2033](http://localhost:2033).

## Run without Docker

```bash
cd examples/ack
php start.php start
```

Then open [http://localhost:2033](http://localhost:2033).

## What to try

1. Click **Client &rarr; Server ack** -- the server acks back immediately; the log
   shows the round-trip time.
2. Click **Server &rarr; Client ack** -- the server asks *your browser* to ack;
   the log shows both the incoming request and the round trip once you reply.
3. Watch the server's terminal output -- every ack exchange (in both directions)
   is logged there too.

## Gotchas found while building this

- **Don't name your own events `ping`/`pong`.** The socket.io/engine.io client
  reserves those internally for its own heartbeat; a server-side
  `$socket->on('ping', ...)` listener for a *custom* event of that name never
  fires. That's why this demo uses `c2s ping`/`s2c ping` instead.
- **A client's ack callback args always arrive server-side as a single array**,
  one entry per argument the client passed to its `ack(...)` call -- e.g.
  `ack(1, 2)` client-side means your PHP ack callback receives `[1, 2]` as its
  only argument, not two separate arguments. This differs from regular event
  listeners (`$socket->on('event', function ($a, $b) {...})`), where the
  library spreads each argument into its own parameter.
