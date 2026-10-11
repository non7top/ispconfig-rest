# Research: ISPConfig Compatibility Checks and Failure Reporting

All unknowns from the Technical Context are resolved below. Facts marked *verified* were read from the repository or the ISPConfig source on disk (a 3.2.11p1 tree) during this planning pass; anything else is a decision.

## R1 — Where the schema level comes from, and how it is cached

- **Decision**: read `server.dbversion` (integer). For the single-server target, use `MIN(dbversion)` over the `server` table: with one row it is that row; if a second row ever appears it errs on the side of refusing, instead of picking one arbitrarily. Multi-server semantics stay a TODO in the spec.
- **Rationale**: *verified* in ISPConfig's `install/lib/update.lib.php` that the updater loads `upd_(dbversion+1).sql` onward and then writes `server.dbversion`; the s24 incident (98 vs 104) is the same key.
- **Cache**: Laravel `Cache` (default `file` store under `storage/framework/cache`, writable by the run user). TTL from `config('api.compat.cache_ttl')`, default 60 s, env `API_COMPAT_CACHE_TTL`. Only a *compatible* result is cached; an incompatible, unknown or unreachable result is never cached, so recovery after ISPConfig's updater is noticed on the next request (FR-010).
- **Alternatives rejected**: `sys_config.db_version` — *verified* that the base SQL inserts it once and no update code touches it. The version string alone — it passed on s24 while the schema was old.

## R2 — ISPConfig version string (informational)

- **Decision**: read the newest `monitor_data` row with `type = 'ispc_info'` and take `data.version`. Parse it with the existing defensive `unserialize(..., ['allowed_classes' => false])` in `MonitorDataService`. Absent row → version `null` ("unknown"), compatibility decided by schema level alone.
- **Rationale**: *verified* that `100-monitor_ispconfig_version.inc.php` writes `type='ispc_info'`, `data['version'] = ISPC_APP_VERSION`. The comparison against `min_version` uses `version_compare`, as ISPConfig itself does, and is informational only (FR-007).
- **Alternatives rejected**: reading `ISPC_APP_VERSION` from `config.inc.php` at runtime — the REST host need not share a filesystem with the panel.

## R3 — Refusing writes before any row is written

- **Decision**: a route middleware alias `compat.writes`, appended to the `api.key` group after authentication, applying to non-safe methods (not GET/HEAD/OPTIONS). It resolves the compatibility report and, when not compatible, throws a typed exception rendered as the 503 problem. It runs before the controller, so no client or other row is created first (FR-008).
- **Rationale**: unauthenticated callers still get 401 first and see no schema information. `GET /api/v1/status` is a GET, so it is never refused.
- **Alternatives rejected**: checking inside `BaseModel::save()` — too late; the s24 orphan clients were created by an earlier model save in the same request.

## R4 — Mapping a missing column or table to a problem

- **Decision**: a `QueryException` branch in `Problem::fromThrowable`. SQLSTATE from `$e->errorInfo[0]` (`42S22` unknown column, `42S02` unknown table). Column from the MySQL message (`Unknown column '<col>'`, which may be qualified `tbl.col`); table from the qualified column, else from the first table identifier after `insert into` / `update` / `from` in the *server-side* SQL. The SQL is parsed only to extract that identifier and is never copied into the response. When the table cannot be determined, the problem names the column only.
- **Rationale**: *verified* from the s24 log that the message is `Unknown column 'disable_symlinknotowner' in 'INSERT INTO'` and does not name the table; the table comes from the statement.
- **Testing**: SQLite cannot raise `42S22`, so tests build a `QueryException` around a `PDOException` whose `errorInfo` is `['42S22', 1054, ...]` and assert the response, the absence of SQL text, and that the full exception is still reported to the log.
- **Type URI**: `urn:ispconfig-rest:problem:incompatible-ispconfig-schema`. RFC 9457 allows any URI reference; a URN needs no resolvable page and does not collide with a future upstream catalogue. Reversible by changing one constant.

## R5 — Logging outside `/usr`, and when logging is broken

- **Decision**: a custom log driver (`via` class) building a Monolog logger whose handler is a `FallbackGroupHandler` of `[RotatingFileHandler(LOG_PATH, 14 days), ErrorLogHandler]`, wrapped so any exception from the handlers is swallowed. `LOG_PATH` defaults to `storage/logs/ispconfig-rest.log` (development and tests); the installer writes `LOG_PATH=/var/log/ispconfig-rest/ispconfig-rest.log` into `.env`, creates the directory mode 0750 owned by the run user, and relies on the application-level 14-day daily rotation (no root logrotate file to maintain).
- **Rationale**: `FallbackGroupHandler` tries each handler until one succeeds, which is exactly "file, else PHP-FPM error log". Swallowing the final failure guarantees the response path never fails because of logging (FR-020).
- **Degraded detection**: a cheap probe — the log directory exists and is writable, and an existing log file is writable — evaluated per status/readiness request. It reports a state, not a history. A fallback taken during the current request also marks the check `degraded`.
- **Alternatives rejected**: Laravel's `stack` with `ignore_exceptions` — it writes to every channel, not "else"; a root logrotate drop-in — one more file the Salt state would have to own for no gain over built-in rotation.

## R6 — Probe routes without session overhead

- **Decision**: `/livez` and `/readyz` live in a new `routes/ops.php` registered through the `then:` hook of `withRouting`, with no middleware group, so they start no session and set no cookie. `/up` (framework health) is untouched. Responses carry `Cache-Control: no-store`.
- **Rationale**: Principle IV says routes live in the versioned, authenticated group; probes cannot carry a key and expose no data, so this is a justified exception (Complexity Tracking).
- **Body**: `{"status":"ok"|"unavailable","checks":{"database":…,"schema":…,"migrations":…,"logging":…}}`, check values `ok|failed|degraded|unknown`. No versions, levels or hostnames.
- **Readiness checks**: `database` — `SELECT 1` on the default connection; `schema` — R1 vs `min_dbversion`; `migrations` — every migration file has a `migrations` row (via the Migrator's file list and the repository's ran list); `logging` — R5 probe, `degraded` never fails readiness (FR-014).

## R7 — `ISPCONFIG_COMPAT` format and readers

- **Decision**: `key=value` lines, `#` comments allowed: `min_dbversion` (int), `min_version`, `tested_version`, and optional `schema_tarball_sha256` (the pin for the CI step in R9; the spec's three keys remain the minimum). One PHP loader, `App\Support\IspconfigCompat::load()`, validates keys and ints and throws a named error for a missing or malformed file; `config/api.php` exposes the result as `api.compat` (the same way PR #10 exposes `VERSION`). `install.sh` and CI read it with `sed -n 's/^key=//p'`.
- **Rationale**: bash must read it before PHP is configured; JSON would force a parser in the installer.

## R8 — Installer and updater pre-flight

- **Decision**: in `install.sh`, after the DB credentials are resolved and before anything is written, read `ISPCONFIG_COMPAT`, connect with PDO using the resolved credentials (`pdo_mysql` is already checked), read `MIN(dbversion)` from `server`, and exit non-zero with a message naming both levels when below the minimum. Re-running `install.sh` is the update path on `core` (`bin/ispconfig-rest update` re-runs it), so one check covers both.
- **Rationale**: *verified* the credentials come from ISPConfig's `config.inc.php` (`read_conf`) or `--db-*` flags, and the privileged DB login is only used for migrations later; a read with the runtime user is enough.

## R9 — CI check that code does not outrun the declared minimum

- **Decision**: collect written columns dynamically and compare them against the real ISPConfig schema.
  1. `Tests\TestCase::setUp()` registers a `DB::listen` collector (when `COMPAT_COLLECT=<path>` is set) that extracts `(table, column)` pairs from `insert` and `update` statements and writes them to the path at shutdown. Because the suite already exercises every write endpoint, this covers models and services without static analysis.
  2. A CI step downloads `ISPConfig-<tested_version>.tar.gz`, verifies `schema_tarball_sha256`, and runs `scripts/check-compat-schema.php`, which parses `install/sql/ispconfig3.sql` (columns per table, the *current* schema) and `install/sql/incremental/upd_NNNN.sql` (`ADD COLUMN` and `CREATE TABLE`) to find the patch that introduces each written column. The required level is the maximum; the step fails if it exceeds `min_dbversion` or if a written column exists in no schema, naming the column and patch.
- **Why not "build the schema up to the minimum level"**: `ispconfig3.sql` in a release is the *current* schema, not the base, so intermediate levels are not directly available; "column present at level N" is equivalent to "its introducing patch ≤ N", which also reports the exact level a change requires.
- **Limits, stated up front**: patches written in PHP (`upd_NNNN.php`) or with procedural SQL are not understood; any statement the parser cannot classify is printed as a warning, not skipped silently. Columns the tests never write are not checked.
- **Alternatives rejected**: static analysis of models/services — misses raw `DB::table(...)->insert` arrays; running the suite against a real MySQL with the patched schema — heavy CI and still needs the same patch sequencing.

## R10 — `doctor`

- **Decision**: artisan command `ispconfig:doctor` running the readiness checks plus the log-directory write check and the migration check with human-readable output, exit 1 on any failure; `bin/ispconfig-rest doctor` calls it as the run user (like `key:create`).

## R11 — Sequencing with open PRs

- Implementation builds on PR #10 (`config/api.php` reads `VERSION`). Branch from `core` after #10 merges, or rebase onto it.
