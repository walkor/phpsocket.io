# ChannelAdapter cross-worker integration test

Proves `ChannelAdapter` delivers across workers: the issue #303 fix
(skipping a dedicated pub/sub channel for auto-joined sid-rooms) and
per-namespace channels. Two independent SocketIO workers, both declaring
the `/` and `/chat` namespaces and wired to a real `workerman/channel`
server, exercise `io.to(sid).emit()`, `io.to(room).emit()` and
namespace-wide emits across the process boundary, check nothing leaks
into the other namespace, and check that the same room name in both
namespaces stays independent.

Not part of `composer test`: it needs real network processes (three Docker
containers) rather than in-process fakes. CI runs it, plus the scale check
below, in the `integration` job of `.github/workflows/ci.yml`.

## Run it

`vendor/` must already exist before starting (e.g. via `docker compose --profile tools run --rm phpunit` once) -- the three containers below share one bind-mounted `vendor/`, so none of them run `composer install` themselves to avoid corrupting it with concurrent writes.

```sh
docker compose up --build -d channel-server channel-worker-a channel-worker-b
npm install socket.io-client@2.5.0   # once, wherever you run the test script
node tests/integration/channel-adapter/test.js
docker compose down channel-server channel-worker-a channel-worker-b
```

Expected output: `PASS` for every scenario, then
`RESULT: ALL PASSED` (exit code 0). Any `FAIL` line or a non-zero exit
code means something regressed.

## Scale check

`scale-check.php` simulates a large number of connections joining their own
sid-room and reports how many pub/sub channels `\Channel\Client` actually
ended up subscribed to -- the exact quantity that grows unbounded pre-fix
and causes issue #303's `Error package. package_length=` crash.

```sh
docker compose up -d channel-server
docker compose run --rm -e CHANNEL_HOST=channel-server -e SCALE_COUNT=10000 \
  channel-worker-a sh -c "php tests/integration/channel-adapter/scale-check.php start"
docker compose down channel-server
```

Measured results (10,000 simulated sid-room joins):

| Code | Subscribed channel count |
|---|---|
| Before this fix | 10,001 |
| After this fix | 1 |
