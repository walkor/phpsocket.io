# Chat Example

A real-time chat demo built with phpsocket.io, featuring:

- Online user list with colored avatars
- Typing indicators
- Message timestamps
- Live online counter
- Server-side debug logging (connections, messages, disconnections)

## Ports

| Service | Port |
|---|---|
| Web (chat UI) | `2027` |
| Socket.IO | `2026` |

## Run with Docker

```bash
docker compose up --build
```

Then open [http://localhost:2027](http://localhost:2027).

## Run without Docker

```bash
php start.php start        # debug mode
php start.php start -d     # daemon mode
php start.php stop         # stop
php start.php status       # status
php start.php restart      # restart
php start.php reload       # reload
php start.php connections  # connections
```

Then open [http://localhost:2027](http://localhost:2027).