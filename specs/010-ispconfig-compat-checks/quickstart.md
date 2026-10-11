# Quickstart: verifying spec 010

Run PHP in a disposable container (no host PHP).

## Automated

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 \
  php artisan test --filter='Compat|Probe|Status|IncompatibleSchema'
```

Expected tests: a synthetic `42S22` / `42S02` `QueryException` becomes the 503 problem with no SQL text; any other query failure stays a generic 500; below-minimum schema refuses POST/PUT/DELETE with no row written and still serves GET; a missing `ISPCONFIG_COMPAT` fails loudly; `/livez` stays 200 with the database failing while `/readyz` is 503; `/readyz` has no versions; `/api/v1/status` is 401 without a key, 403 with a non-admin key, 200 for an admin key; an unwritable log directory still returns the problem response and marks logging `degraded`.

## CI schema check

```sh
COMPAT_COLLECT=build/written-columns.json php artisan test
php scripts/check-compat-schema.php build/written-columns.json
```

The second command downloads the pinned ISPConfig tarball (`tested_version`) and fails naming any written column whose introducing patch is above `min_dbversion`.

## Manual (staging host)

1. `curl -s http://127.0.0.1:<port>/livez` and `/readyz` → 200 `ok`.
2. Lower the level to simulate an old restore: `UPDATE server SET dbversion = dbversion - 6` on a **copy** of `dbispconfig`.
3. `/readyz` → 503 with `"schema":"failed"`; `curl -X POST …/api/v1/clients` → 503 problem naming both levels and creating no `client` row; `curl …/api/v1/clients` still 200.
4. `ispconfig-rest doctor` shows the same failure and exits 1; `GET /api/v1/status` with an administrator key shows the numbers.
5. Make the log directory read-only: trigger an error; the client still gets its problem response, the entry is in the PHP-FPM error log, `logging` reads `degraded`.
