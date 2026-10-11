# Feature Specification: ISPConfig Compatibility Checks and Failure Reporting

**Feature Branch**: `010-ispconfig-compat-checks`
**Created**: 2026-10-11
**Status**: Draft
**Module**: cross-cutting (system / server)
**Input**: User description: "Spec 010: ISPConfig compatibility checks and failure reporting — report a missing column or table as an actionable typed problem instead of a bare 500, log every failure outside `/usr` and survive a broken log, declare the minimum ISPConfig schema level in one file, check it at install and at runtime, and expose Kubernetes-style liveness and readiness probes plus an authenticated status report."

## Background (verified incident)

On s24 the installed ISPConfig code was 3.3.1p1 but `dbispconfig` had been restored from an older host: `server.dbversion` was 98 while 3.3.1p1 ships schema patches up to `upd_0104`. `POST /sites/web-domains` on REST `v1.0.0-rc.3` failed with `Unknown column 'disable_symlinknotowner'` (added by `upd_0100`). The caller saw a bare `HTTP 500` problem with no cause (`Problem::fromThrowable` shows `detail` only when `app.debug` is on), nothing reached `storage/logs/laravel.log`, and orphan clients were left behind because the failure happened after the client row was created. The ISPConfig version string would have passed; the schema level is what was wrong.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A schema mismatch is reported, not hidden (Priority: P1)

An integration calls any endpoint against an ISPConfig database whose schema lacks a column or table the API needs. Instead of a bare 500, it receives a `503` problem of type `incompatible-ispconfig-schema` whose `detail` names the missing table and column and says that the ISPConfig database schema is older than the API expects.

**Why this priority**: This is the failure that actually happened. It is the smallest change that turns a silent, undiagnosable outage into an actionable one, and it is the safety net when every other check is stale or bypassed.

**Independent Test**: Drop a column the API writes (for example `web_domain.disable_symlinknotowner`) from the test database and `POST /api/v1/sites/web-domains`. Expect `503`, `Content-Type: application/problem+json`, `type` ending in `incompatible-ispconfig-schema`, `detail` containing `web_domain` and `disable_symlinknotowner`, and no SQL text, bound value or connection detail anywhere in the body.

**Acceptance Scenarios**:

1. **Given** a missing column (SQLSTATE `42S22`), **When** any request triggers the query, **Then** the response is a 503 problem naming only the table and column.
2. **Given** a missing table (SQLSTATE `42S02`), **When** any request triggers the query, **Then** the response is a 503 problem naming only the table.
3. **Given** any other query failure (for example a deadlock or a constraint error), **When** it occurs, **Then** the response stays the generic 500 problem and its detail stays hidden unless `app.debug` is on.
4. **Given** the problem is returned, **When** the operator reads the log, **Then** the full exception, including the SQL, is in the log and never in the response.

---

### User Story 2 - An incompatible installation is refused before it writes (Priority: P1)

The API compares the ISPConfig schema level it finds with the minimum it was built for. When the database is older than the minimum, every write request is refused with a 503 problem of type `incompatible-ispconfig-schema` that names both levels, before any row is created. Reads keep working. The installer refuses to install or update below the minimum.

**Why this priority**: Reporting a failure after the first row is written still leaves orphans (the s24 clients). Refusing the whole write up front removes the partial-write hazard.

**Independent Test**: Set the test database's schema level below the declared minimum. `POST /api/v1/clients` returns 503 and creates no `client` row and no `sys_datalog` row. `GET /api/v1/clients` still returns 200. Run the installer against the same database and it exits non-zero before changing anything.

**Acceptance Scenarios**:

1. **Given** schema level 98 and minimum 100, **When** a client sends any POST, PUT or DELETE under `/api/v1`, **Then** the response is 503 with `detail` naming levels 98 and 100, and nothing is written.
2. **Given** the same state, **When** a client sends a GET, **Then** it is served normally.
3. **Given** schema level 104 and minimum 100, **When** writes arrive, **Then** they are served normally.
4. **Given** the installer or updater runs against a database below the minimum, **When** it checks, **Then** it stops with a message naming both levels and does not install or update.
5. **Given** the level is read once, **When** the operator runs ISPConfig's updater, **Then** the API notices the new level within the cache lifetime without a restart.

---

### User Story 3 - Probes and a status report an orchestrator and an operator can use (Priority: P2)

The API exposes Kubernetes-style probes. Liveness says the process is up and depends on nothing. Readiness says the API can serve requests: the database is reachable, the schema level is compatible and the API's own tables are migrated. Both are unauthenticated and reveal no versions. An authenticated administrator can read a full status report with the ISPConfig version, the schema level, the required level and the logging state. An `ispconfig-rest doctor` command prints the same checks for a shell user.

**Why this priority**: It makes US2 visible to the tools that act on it (load balancers, orchestrators, monitoring, Salt) and gives a human the exact numbers. It builds on US1 and US2, so it follows them.

**Independent Test**: With a compatible database, `GET /livez` and `GET /readyz` return 200. Lower the schema level below the minimum: `GET /livez` stays 200, `GET /readyz` returns 503, and the authenticated status report shows both levels. Run `doctor` and see the same failure with a non-zero exit code.

**Acceptance Scenarios**:

1. **Given** a healthy installation, **When** `GET /readyz` is called without a key, **Then** it returns 200 with only per-check states (`ok`), no versions or levels.
2. **Given** an incompatible schema, **When** `GET /readyz` is called, **Then** it returns 503 and the failing check is named `schema`.
3. **Given** the database is unreachable, **When** `GET /livez` is called, **Then** it still returns 200, and `GET /readyz` returns 503 with `database` failing.
4. **Given** the logging fallback is in use, **When** `GET /readyz` is called, **Then** readiness stays 200 and the `logging` check reports `degraded`.
5. **Given** an administrator key, **When** `GET /api/v1/status` is called, **Then** it returns the ISPConfig version, the schema level, the required level and `compatible`; a non-administrator key gets 403 and no key gets 401.
6. **Given** a shell user on the host, **When** `ispconfig-rest doctor` runs, **Then** it prints each check and exits non-zero if any check fails.

---

### User Story 4 - Failures are always logged, outside `/usr`, and survive a broken log (Priority: P2)

Every unhandled failure is written to a log under `/var/log/ispconfig-rest/`, not under the application directory in `/usr/local/ispconfig`. If that log cannot be written, the API falls back to the PHP-FPM error log, and if that fails too it still returns the problem response. Logging can never turn one error into two or make the response path fail.

**Why this priority**: The s24 failure left no trace. Without logging, US1's safety net still leaves an operator guessing. It is P2 because the response itself (US1) matters first.

**Independent Test**: Make the log directory read-only and trigger an unhandled exception. The client still gets the problem response, the entry appears in the PHP-FPM error log, and the status report shows `logging: degraded`. Restore the directory and the next failure is written to the file.

**Acceptance Scenarios**:

1. **Given** a writable log directory, **When** any unhandled exception occurs, **Then** one entry with the full exception is written to `/var/log/ispconfig-rest/`.
2. **Given** an unwritable log directory, **When** an exception occurs, **Then** the entry goes to the PHP-FPM error log and the client response is unchanged.
3. **Given** both destinations fail, **When** an exception occurs, **Then** the client response is unchanged and no second exception escapes.
4. **Given** a fresh install, **When** the installer finishes, **Then** the directory exists, is owned by the application user, and log rotation is configured.
5. **Given** `doctor` runs, **When** the log directory is not writable, **Then** the `logging` check is reported as failed.

---

### User Story 5 - Code that needs a newer schema fails CI until the minimum is raised (Priority: P3)

The required schema level lives in one file, `ISPCONFIG_COMPAT`. A CI check builds the ISPConfig schema from the base SQL plus the incremental patches up to that level and verifies that every column the models and services write exists. A change that starts using a column from a newer patch fails CI until the minimum is raised in the same change.

**Why this priority**: It keeps the declared minimum honest without anyone remembering to edit it. It is P3 because the runtime checks protect users first and this protects maintainers.

**Independent Test**: Make a model write a column that exists only in a patch above the declared minimum. CI fails and names the column and the patch. Raise the minimum in `ISPCONFIG_COMPAT` and CI passes.

**Acceptance Scenarios**:

1. **Given** the declared minimum is 100, **When** the code writes a column added in patch 101, **Then** the CI check fails naming the column and the patch.
2. **Given** the minimum is raised to 101, **When** CI runs, **Then** it passes.
3. **Given** the minimum file is missing or malformed, **When** the application, installer or CI reads it, **Then** each fails loudly with a message naming the file.

---

### Edge Cases

- **Multi-server installations are out of scope.** Each `server` row carries its own `dbversion`, and which row is authoritative when several servers update the shared schema is undecided. This feature supports single-server installations only. TODO: rework the schema-level check for multi-server management (see Assumptions).
- **Schema level above anything known.** A level higher than the latest patch the API was built against is compatible; the API only requires a minimum.
- **No `ispc_info` monitor row yet** (fresh install, monitor cron has not run). The ISPConfig version is reported as unknown and compatibility is decided from the schema level alone.
- **Version string with a patch suffix** (`3.3.1p1`). Compared with PHP `version_compare`, which ISPConfig itself uses, so `3.3.1p1` is above `3.3.1`.
- **Stale cache.** After ISPConfig's updater runs, the cached level may be stale for up to the cache lifetime; the 503 refusal and the installer check both re-read when the cached result is a failure.
- **The check itself cannot run** (database unreachable). Writes fail as they do today; readiness reports `database` failing; the API does not guess that it is compatible.
- **Missing `server` row or unreadable `dbversion`.** Reported as `unknown`, not as compatible; writes are refused with a problem that says the level could not be determined.
- **Problem responses leak nothing.** Only table and column names appear; never SQL, bound values, hostnames or credentials.
- **Request-scoped writes already in flight** when the level drops are not interrupted; the next write is refused.

## API Contract *(mandatory)*

- **Spec file(s)**: probes and status are authored first, then implemented: `api/` gains the `incompatible-ispconfig-schema` problem component and a `GET /api/v1/status` operation (new — to be authored first). `GET /livez` and `GET /readyz` are unauthenticated operational routes outside `/api/v1`, documented in the spec as such.
- **Shared schemas**: a `ProblemIncompatibleSchema` response component and a `Status` schema (new).
- **Endpoints**:

| Method | Path | Purpose | Auth | Success code |
|--------|------|---------|------|--------------|
| GET | `/livez` | Liveness: process is up, no dependencies | none | 200 |
| GET | `/readyz` | Readiness: database, schema compatibility, API tables; per-check states only | none | 200 / 503 |
| GET | `/api/v1/status` | Full report: ISPConfig version, schema level, required level, `compatible`, logging state | admin | 200 |
| any write | `/api/v1/*` | Refused while the schema is below the minimum | existing | 503 problem |

- **Existing routes**: `GET /` (name, version, documentation link) stays unauthenticated and minimal and gains no version or schema data. The framework's `/up` health route stays as it is; `/livez` is its Kubernetes-style alias. `/api/v1/monitor/servers/status` is unrelated: it reports ISPConfig servers, not the API.
- **Problem type**: `incompatible-ispconfig-schema`, status 503, with members naming the table and column (reporting) or the found and required levels (refusal). Core has no problem-type catalogue yet, so this feature introduces the type URI convention with this first type.

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: `install/lib/update.lib.php` (the updater loads `upd_(dbversion+1).sql` onward and then writes `server.dbversion`), `install/sql/incremental/upd_NNNN.sql`, and `server/lib/classes/cron.d/100-monitor_ispconfig_version.inc.php` (writes the `ispc_info` monitor row).
- **Legacy behaviors to mirror**: the schema level is the integer `server.dbversion`; versions compare with `version_compare`.
- **Tables written (via datalog only)**: none. This feature is read-only against ISPConfig tables (`server`, `monitor_data`). The API never applies ISPConfig schema patches; that stays with ISPConfig's own updater (Principle II).
- **System fields handling**: not applicable.
- **Intentional deviations from legacy**: none.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A query failure with SQLSTATE `42S22` or `42S02` MUST produce a 503 `application/problem+json` response of type `incompatible-ispconfig-schema` naming only the table and, for `42S22`, the column.
- **FR-002**: Response bodies MUST NOT contain SQL text, bound values, hostnames or credentials for any failure, regardless of `app.debug` state for these two SQLSTATEs.
- **FR-003**: All other unhandled failures MUST keep the generic 500 problem, with the cause hidden unless `app.debug` is on.
- **FR-004**: The required schema level MUST be declared in a single file, `ISPCONFIG_COMPAT`, at the repository root beside `VERSION`, as `key=value` lines `min_dbversion`, `min_version` and `tested_version`.
- **FR-005**: The application, the installer, the `doctor` command and CI MUST all read that file; no component may hard-code the minimum.
- **FR-006**: A missing or malformed `ISPCONFIG_COMPAT` MUST fail each reader loudly with a message naming the file.
- **FR-007**: The API MUST read the schema level from the `dbversion` of the installation's single `server` row and MAY read the ISPConfig version string from the `ispc_info` monitor row; compatibility MUST be decided by the schema level, with the version string informational.
- **FR-008**: While the schema level is below `min_dbversion`, every write request under `/api/v1` MUST be refused with a 503 problem of type `incompatible-ispconfig-schema` naming the found and required levels, before any row is written; read requests MUST continue to be served.
- **FR-009**: When the level cannot be determined, writes MUST be refused (not assumed compatible) with a problem that says so.
- **FR-010**: The result of the schema check MUST be cached for a short, configurable time and MUST be re-read when the cached result is a failure.
- **FR-011**: The installer and updater MUST refuse to proceed, before changing anything, when the database is below `min_dbversion`, naming both levels.
- **FR-012**: `GET /livez` MUST return 200 whenever the process can respond, with no dependency checks.
- **FR-013**: `GET /readyz` MUST be unauthenticated, MUST return 200 only when the database is reachable, the schema level is compatible and the API's own tables are migrated, and MUST otherwise return 503; its body MUST contain per-check states only, with no versions, levels or hostnames.
- **FR-014**: A degraded logging state MUST NOT fail readiness; it MUST be reported as `degraded` in the `logging` check.
- **FR-015**: `GET /api/v1/status` MUST require an administrator key and MUST return the ISPConfig version, schema level, required level, `compatible` and logging state.
- **FR-016**: `GET /` MUST remain unauthenticated and MUST NOT expose the ISPConfig version, schema level or any check result.
- **FR-017**: `ispconfig-rest doctor` MUST run the same checks as readiness plus the log-directory write check and the migration check, print each result, and exit non-zero if any check fails.
- **FR-018**: Every unhandled failure MUST be written with its full exception to a log under `/var/log/ispconfig-rest/`, not under the application directory.
- **FR-019**: The installer MUST create that directory owned by the application user and configure log rotation for it.
- **FR-020**: A failure to write the log MUST NOT change or delay the client response; the entry MUST fall back to the PHP-FPM error log, and if that fails too, MUST be dropped silently without raising another exception.
- **FR-021**: CI MUST build the ISPConfig schema from the base SQL plus incremental patches up to `min_dbversion` and MUST fail when any column the models and services write is absent, naming the column and the patch that adds it.
- **FR-022**: The API MUST NOT apply ISPConfig schema patches or write any ISPConfig table as part of these checks.

### Key Entities *(include if feature involves data)*

- **Compatibility declaration**: the file `ISPCONFIG_COMPAT` (`min_dbversion`, `min_version`, `tested_version`); not stored in the database.
- **Schema level**: the integer `dbversion` of the installation's single `server` row — table `server`, read-only.
- **ISPConfig version record**: the `ispc_info` row in `monitor_data` (`data.version`) — read-only, informational.
- **Status report**: the response of `GET /api/v1/status` and the output of `doctor`; derived, not stored.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With a missing column or table, 100% of affected requests return a problem that names the table (and column) and none contains SQL or bound values.
- **SC-002**: Below the minimum schema level, no write request creates a row in any ISPConfig table or `sys_datalog`, verified across every write endpoint category (clients, DNS, mail, sites).
- **SC-003**: An operator can tell from one command (`doctor`) or one authenticated call (`/api/v1/status`) why the API is refusing writes, without reading a log or enabling debug mode.
- **SC-004**: With the log directory unwritable, the client still receives the correct problem response in 100% of triggered failures, and the entry is found in the fallback log.
- **SC-005**: A pull request that makes the code depend on a column from a patch above `min_dbversion` fails CI every time until the minimum is raised.
- **SC-006**: Liveness stays 200 while the database is down; readiness turns 503 within the cache lifetime of the failure and returns to 200 within the cache lifetime of recovery.
- **SC-007**: A restored `dbispconfig` older than the installed ISPConfig, as on s24, is detected by the installer, by `doctor` and by readiness before the first failed write.

## Assumptions

- The installation has a single server, so the schema level is the `dbversion` of that one `server` row. TODO: rework for multi-server management. The open question is which row is authoritative when several servers run ISPConfig's updater against the shared schema; it has no agreed answer yet, and a multi-server installation is not a supported target of this feature until it does.
- The minimum for this fork starts at the schema level of ISPConfig 3.3.1p1's patch needed by the current code (100, from `upd_0100`) and is raised only when code needs it; `tested_version` is `3.3.1p1`.
- `/livez` and `/readyz` are the Kubernetes naming convention; `/up` (framework default) remains for compatibility and is treated as an alias of liveness.
- The application user already created by the installer owns the log directory; the Salt state that creates the user and directories is updated separately.
- Readiness checks the API's own migrations (`api_*` tables) because a `dbispconfig` restore can clobber them, as it did to `api_keys` on s24.
- Detailed ISPConfig data never leaves an authenticated, administrator-scoped endpoint or a shell.
- The ISPConfig schema sources (`ispconfig3.sql` and the incremental patches) for the declared minimum are available to CI from the ISPConfig release tarball.
