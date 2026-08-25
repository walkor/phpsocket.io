# Rooms Example

A minimal demo of phpsocket.io's room APIs (`socket->join()`, `socket->leave()`,
`socket->to(room)->emit()`), showing:

- Joining a room under a nickname
- Leaving a room (or switching to a different one)
- Sending a message to everyone else currently in the same room
- A live member list that updates as people join/leave

## Ports

| Service | Port |
|---|---|
| Web (rooms UI) | `2031` |
| Socket.IO | `2030` |

## Run with Docker

```bash
docker compose up --build rooms
```

Then open [http://localhost:2031](http://localhost:2031) in two browser tabs to see
messages, joins, and leaves propagate between them.

## Run without Docker

```bash
php start.php start        # debug mode
php start.php start -d     # daemon mode
php start.php stop         # stop
php start.php status       # status
```

Then open [http://localhost:2031](http://localhost:2031).

## What to try

1. Open the page in two tabs, join the same room with different nicknames.
2. Send a message from one tab -- it should appear in the other.
3. Click "Leave" in one tab -- the other tab's member list should update.
4. Join a *different* room name in one tab -- it leaves the first room
   automatically (only one room at a time per connection in this demo).
