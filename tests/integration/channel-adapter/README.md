# ChannelAdapter cross-worker integration test

Proves the issue #303 fix (`ChannelAdapter` skipping a dedicated pub/sub
channel for auto-joined sid-rooms) doesn't break cross-worker delivery --
the thing that fix could plausibly have broken. Two independent SocketIO
workers, wired to a real `workerman/channel` server, exercise both
`io.to(sid).emit()` and `io.to(room).emit()` across the process boundary.

Not part of `composer test` / CI: it needs real, timing-sensitive network
processes (three Docker containers) rather than in-process fakes, so it's
a manual/CI-optional check, not something that runs on every commit.

## Run it

`vendor/` must already exist before starting (e.g. via `docker compose --profile tools run --rm phpunit` once) -- the three containers below share one bind-mounted `vendor/`, so none of them run `composer install` themselves to avoid corrupting it with concurrent writes.

```sh
docker compose up --build -d channel-server channel-worker-a channel-worker-b
npm install socket.io-client@2.5.0   # once, wherever you run the test script
node tests/integration/channel-adapter/test.js
docker compose down channel-server channel-worker-a channel-worker-b
```

Expected output: `PASS` for both cross-worker scenarios, then
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
