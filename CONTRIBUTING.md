# Contributing

Thanks for taking the time to contribute to phpsocket.io!

## Getting started

```bash
git clone https://github.com/walkor/phpsocket.io.git
cd phpsocket.io
composer install
```

No PHP toolchain locally? Use Docker instead — no PHP or Composer install needed on your machine:

```bash
docker compose --profile tools run --rm phpcs
docker compose --profile tools run --rm phpunit
```

## Running checks locally

Both checks below run in CI on every pull request.

**Coding standard (PHP_CodeSniffer, PSR-2 based):**

```bash
composer lint
# or: vendor/bin/phpcs src/
```

You can auto-fix most style issues with:

```bash
vendor/bin/phpcbf src/
```

**Unit tests (PHPUnit):**

```bash
composer test
# or: vendor/bin/phpunit
```

CI runs the test suite against PHP 7.4 through 8.5. Please add or update tests under `tests/Unit`
for any change to `src/`.

## Submitting a pull request

- Keep pull requests focused on a single change; unrelated fixes make review harder.
- Make sure `composer lint` and `composer test` pass before opening the PR.
- Describe the *why* behind the change in the PR description, not just the *what*.
- If you're fixing a bug, a regression test that fails without your fix (and passes with it) is
  the fastest way to get a review approved.
- For behavior changes affecting the public API, please open an issue first to discuss the
  approach — this project only supports Socket.IO client v1.3.0–v2.x, so backwards compatibility
  matters.

## Reporting issues

Please include:

- The phpsocket.io and Workerman versions in use (`composer show`).
- The Socket.IO client version connecting to the server.
- A minimal reproduction script, if possible.
