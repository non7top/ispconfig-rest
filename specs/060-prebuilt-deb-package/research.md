# Research: Prebuilt Debian Package and Salt-callable Migrations

Decisions with the evidence behind them. "Verified" means read or run in this repository during the work; everything else is marked as an expectation.

## R1 — One artifact, not one per PHP minor
**Decision**: one architecture-independent package. **Evidence**: `composer.json` sets `config.platform.php` to 8.3.0; the generated `platform_check.php` of a dev install only requires `PHP_VERSION_ID >= 80300` (verified); the vendor tree has no `.so` files (verified); the suite passes in CI on 8.3, 8.4 and 8.5. **Rejected**: a per-minor matrix would produce identical bytes.

## R2 — `.deb` with `apt-cosign`, not a tarball
**Decision**: a `.deb`, published as a flat repository on an `apt-repo` branch and signed keylessly in the release workflow. **Evidence**: `non7top/apt-cosign` verifies a Sigstore bundle for `InRelease`, `Packages` and each `.deb` against a GitHub Actions identity, ships a reusable `publish-apt-repo` action and a `fetch-apt-cosign-sign` action (README and `action.yml` read). **Rejected**: tarball (no file tracking, own rollback logic); GPG-signed repository (key management).

## R3 — Migrations are a separate, deliberate command
**Decision**: `ispconfig-rest migrate [--check]`; no maintainer script runs it. **Why**: the migration needs DDL rights the runtime user lacks, and a restore of `dbispconfig` drops `api_keys` together with the `migrations` table, so an unattended run would silently recreate an empty table; the command says so. **Rejected**: migrate on first use (needs DDL for the runtime user); moving keys to SQLite (parked, see `ispconfig-rest-parked-ideas`).

## R4 — One implementation: artisan command wrapped by the manager
**Decision**: `app/Console/Commands/Migrate.php` (`ispconfig:migrate`); `bin/ispconfig-rest migrate` and `install.sh` call it. **Evidence**: `HealthCheckService` already lists pending migrations through the `Migrator` (verified, `migrations()` check); `install.sh` today runs `artisan migrate --force` with `DB_USERNAME`/`DB_PASSWORD` overridden by the admin login (verified, `migrate_privileged`). Real environment variables win over `.env` with Laravel's immutable loader, which is how the override works today.

## R5 — Privileged login and file ownership
**Decision**: the login is `ISPC_REST_DB_ADMIN_USER` (default `root`) and `ISPC_REST_DB_ADMIN_PASS`; with the default root and no password the command runs as OS root so unix-socket authentication works, as `install.sh` does now. As root it runs with `LOG_CHANNEL=stderr` and `CACHE_STORE=array` so it leaves no root-owned file in `/var/log` or `/var/cache`. With any other login it runs as the service user.

## R6 — Lock
**Decision**: a MySQL `GET_LOCK('ispconfig-rest:migrate', 30)` on the migration connection; skipped on drivers without it (SQLite in tests). A second run waits up to 30 s, then exits 1 "another migration is running".

## R7 — Environment path
**Decision**: `bootstrap/app.php` calls `useEnvironmentPath('/etc/ispconfig-rest')` when `/etc/ispconfig-rest/.env` exists, otherwise the tree is used, so existing installs keep working. **Evidence**: `Application::useEnvironmentPath()` and `environmentPath()` exist in the vendored framework (verified). The `APP_*_CACHE` and `LARAVEL_STORAGE_PATH` keys from `.env` already move every write to `/var/cache/ispconfig-rest` (verified with a read-only tree).

## R8 — Package layout and users
**Decision**: tree in `/usr/share/ispconfig-rest`; system user and group `ispconfig-rest` (`useradd --system`, idempotent); `/etc/ispconfig-rest` root:ispconfig-rest 0750 for the environment file; `/var/log/ispconfig-rest` and `/var/cache/ispconfig-rest` (with the framework subdirectories) 0750 owned by the user, recreated at boot by a `tmpfiles.d` file. No logrotate file: the `resilient` channel rotates daily and keeps 14 days (verified in `config/logging.php`). `purge` removes the cache directory only; the user stays (Debian policy). **Expectation, not verified**: `useradd` and `systemd-tmpfiles` are present on the target; the container test installs the package in `php:8.3-cli` (Debian) and checks the result.

## R9 — Release workflow
**Decision**: trigger on a pushed tag matching `[0-9]*`; fail when `VERSION` differs from the tag; build with `composer install --no-dev --classmap-authoritative --no-scripts` in a container; wrap with `dpkg-deb`; run `scripts/test-deb.sh`; sign and publish with the apt-cosign actions (`permissions: id-token: write, contents: write`); attach the `.deb` and its sha256 to a GitHub release. `--no-scripts` because `package:discover` needs the dev providers; the manifest is generated at runtime into `APP_PACKAGES_CACHE`. **Checked in slice 4**: the fork's ruleset `default` (deletion, non-fast-forward, pull request) includes only `~DEFAULT_BRANCH`, so force-publishing the `apt-repo` branch and pushing tags are allowed (read from the rulesets API).
