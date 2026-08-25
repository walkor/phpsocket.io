# Upgrading to v3.0.0

v3.0.0 is a breaking release: it raises the minimum PHP version, removes an
internal debug-logging leftover, and locks down a handful of properties that
were never meant to be touched from outside the library. It also fixes 10
real correctness bugs found while adding test coverage -- most of these are
pure bug fixes with no action needed, but a couple change *observable*
behavior on the wire, so they're called out below too.

## Checklist

- [ ] **PHP >= 7.4** is now required (was >= 7.1). `composer require`/`update`
      will refuse to install this version on an older PHP.
- [ ] If you relied on `global $debug = true;` for `PHPSocketIO\Debug`'s
      constructor/destructor tracing output, that class is gone. There's no
      direct replacement -- log from your own event handlers instead.
- [ ] If your code reads any of these properties **directly**, switch to the
      new getter method -- they're `protected` now:

      | Was | Now |
      |---|---|
      | `$socket->flags` | `$socket->getFlags()` |
      | `$socket->acks` | `$socket->getAcks()` |
      | `$socket->_rooms` | `$socket->getRoomTargets()` |
      | `$nsp->rooms` | `$nsp->getRoomTargets()` |
      | `$nsp->flags` | `$nsp->getFlags()` |

      (We checked real-world usage of this library on GitHub before making
      this change and found zero hits for any of these five -- they're all
      ephemeral per-`emit()` bookkeeping, not documented/intended API. If
      you're not sure, grep your codebase for `->acks`, `->flags`, or
      `->_rooms` on a socket/namespace object to be safe.)
- [ ] If you were setting `$nsp->fns`, `$nsp->acks`, or `$io->_path`\* -- stop.
      These were dead properties that never did anything; removing them
      changes no behavior, but referencing them will now be a PHP error
      instead of a silent no-op.
      <br>\* `_path` was `protected`, so only relevant if you subclassed `SocketIO`.
- [ ] Double-check any code that **assigns** to a library property directly
      (`$socket->someProp = ...`). Most public properties now have a
      declared type; assigning an incompatible type (e.g. a string to a
      property typed `bool`) now throws a `TypeError` instead of being
      silently accepted.

## Behavior fixes worth knowing about

These aren't things you need to change -- they're bugs that are now fixed.
Listed here in case something in your application was, knowingly or not,
compensating for the old (broken) behavior.

- **`Engine\Parser`**: sending more than one engine.io packet in a single
  payload (text or binary) no longer corrupts the data of the packets
  involved. If you ever saw garbled/truncated message content under load,
  this was likely why.
- **`DefaultAdapter::clients($rooms, $fn)`**: the callback now actually
  receives the matching socket ids as its argument
  (`function ($sids) { ... }`). Previously it was called with no arguments
  at all. If your callback didn't declare a parameter, nothing changes for
  you; if you were working around the missing ids some other way, you can
  now simplify to just use the argument.
- **`Socket::emit($event, $data, $ackCallback)`**: emitting with a
  server-side ack callback no longer leaks the callback (as a bogus `{}`)
  into the event's data on the wire. If client-side code had a workaround
  for an unexpected extra argument on events that use acks, it can be
  removed.
- **`Socket::ack($id)`**: the "don't send the ack twice" guard now actually
  works. Calling the same ack callback more than once used to re-send the
  ack packet every time; now only the first call sends anything.
- **`Nsp::send()` / `$io->send()`**: broadcasts sent via `send()`/`write()`
  no longer arrive with corrupted (double-nested) data. If you had
  client-side unwrapping logic to cope with this, it can be removed.
- **JSONP polling (`?j=N` fallback transport)**: this was completely broken
  -- every connection attempt via JSONP fataled instantly. It now works. No
  action needed; this only affects very old browsers without CORS/XHR2
  support, which is why it likely went unnoticed for so long.
- **`Client::$sockets`**: now defaults to `[]` instead of `null`. This only
  matters if a connection closed or errored before completing its first
  namespace join; previously that could raise a PHP warning internally.

## What's new

- Four runnable examples under `examples/`: `chat` (updated), `rooms`, `ack`,
  and `namespaces` -- see the [README](README.md#run-the-examples).
- Compatibility verified end-to-end against the real `socket.io-client@2.5.0`
  (the last 2.x release) over both polling and WebSocket transports.
- A `CONTRIBUTING.md` guide and a Docker-based dev workflow that needs no
  local PHP/Composer install (`docker compose --profile tools run --rm
  phpunit`).
- A real PHPUnit test suite (0 -> 190+ tests) covering the vast majority of
  `src/`, running in CI across PHP 7.4-8.5.
