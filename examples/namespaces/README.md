# Namespaces Example

A minimal demo of `io->of('/path')`: two fully independent communication
channels (`/public` and `/admin`) sharing the same server and port. A message
sent in one namespace never reaches a client connected to the other, even
though both use the exact same Socket.IO connection endpoint.

This is a different kind of grouping than [rooms](../rooms) -- a namespace is
a separate channel you connect *to* (`io(url + '/admin')`), not a subset of
clients within a single connection you can join and leave at will.

## Ports

| Service | Port |
|---|---|
| Web (demo UI) | `2035` |
| Socket.IO | `2034` |

## Run with Docker

```bash
docker compose up --build namespaces
```

Then open [http://localhost:2035](http://localhost:2035).

## Run without Docker

```bash
cd examples/namespaces
php start.php start
```

Then open [http://localhost:2035](http://localhost:2035).

## What to try

1. Open the page in two tabs. Connect both to **Public**.
2. Send a message from one tab -- it appears in the other.
3. Open a third tab, connect it to **Admin** instead.
4. Send a message from the Public tabs -- the Admin tab never sees it (and
   vice versa). Check the server's terminal output: every connection and
   message is logged with which namespace it belongs to.
