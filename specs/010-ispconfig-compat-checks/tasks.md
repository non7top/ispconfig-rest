---

description: "Task list for spec 010: ISPConfig compatibility checks and failure reporting"
---

# Tasks: ISPConfig Compatibility Checks and Failure Reporting

**Input**: Design documents from `/specs/010-ispconfig-compat-checks/`
**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md

**Tests**: REQUIRED (constitution v2). Every endpoint and component ships with tests. Run PHP in a disposable container (no host PHP), for example `docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 php artisan test`.

**Organization**: grouped by user story. Stories are ordered by priority, with US4 (logging) placed before US3 (probes) because the readiness `logging` check reads the log probe that US4 creates. All tasks write nothing to ISPConfig tables (Principle II): the feature reads `server`, `monitor_data` and `migrations` only.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: the user story the task belongs to (US1–US5)
- Never mark two edits to the same file `[P]` (`bootstrap/app.php`, `install.sh`, `config/api.php`, `routes/api.php` are shared)

## Path Conventions (this project)

| Artifact | Path |
|----------|------|
| OpenAPI endpoint spec | `api/modules/system/status.yaml` (+ register in `api/modules/system/_index.yaml` and `api/openapi.yaml`) |
| OpenAPI components | `api/components/{schemas,responses}/` |
| Service | `app/Services/[Name]Service.php` |
| Support classes | `app/Support/` (logging under `app/Support/Logging/`) |
| Controllers | `app/Http/Controllers/Api/V1/` (probes: `app/Http/Controllers/ProbeController.php`) |
| Routes | `routes/api/system.php` (inside the `scope.admin` group), `routes/ops.php` (probes, no middleware group) |
| Tests | `tests/Feature/`, `tests/Unit/` |

---

## Phase 1: Setup

**Purpose**: the single source of truth and the shared configuration exist before any PHP uses them

- [x] T001 Create `ISPCONFIG_COMPAT` at the repository root with `min_dbversion=100`, `min_version=3.3.1p1`, `tested_version=3.3.1p1` and explanatory `#` comments, in the format of `contracts/compat-file-and-doctor.md` (the optional `schema_tarball_sha256` is added by T033)
- [x] T002 [P] Add `API_COMPAT_CACHE_TTL=60` and `LOG_PATH=` (commented default `storage/logs/ispconfig-rest.log`) with one-line comments to `.env.example`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: pieces every story uses: the loader, the config block, the problem type and the exception

**⚠️ CRITICAL**: no user story work can begin until this phase is complete

- [x] T003 Implement `App\Support\IspconfigCompat::load()` in `app/Support/IspconfigCompat.php`: parse `ISPCONFIG_COMPAT` (`key=value`, `#` comments, blank lines), require `min_dbversion` (int ≥ 1), `min_version`, `tested_version`, accept optional `schema_tarball_sha256` (64 hex), reject unknown keys, and throw an error that names the file for a missing file, a missing key or a malformed value (FR-004–FR-006)
- [x] T004 [P] Unit test `tests/Unit/IspconfigCompatTest.php`: valid file, comments and blank lines, missing file, missing key, non-integer level, bad sha256, unknown key (each error names `ISPCONFIG_COMPAT`)
- [x] T005 Add the `compat` block to `config/api.php`: `compat` => `IspconfigCompat::load()` result, `compat_cache_ttl` => `env('API_COMPAT_CACHE_TTL', 60)`; keep the existing `VERSION` read untouched
- [x] T006 Register the problem type: add `INCOMPATIBLE_SCHEMA = 'incompatible-ispconfig-schema'` to `app/Support/ProblemType.php` (constant and `NAMES`) and an `incompatible-ispconfig-schema` section (status 500, members `table`, `column`, `found_dbversion`, `required_dbversion`) to `docs/problems.md`; keep the catalogue-versus-documentation consistency test green
- [x] T007 Create `app/Exceptions/IncompatibleSchemaException.php` carrying either table/column or found/required levels (or "level unknown"), with a message that never contains SQL; render it in `app/Support/Problem.php` as a 500 problem with `type` = `ProblemType::uri(ProblemType::INCOMPATIBLE_SCHEMA)` and the members above
- [x] T008 [P] Author `api/components/responses/IncompatibleSchema.yaml` from `contracts/api-probes-status.yaml` and register it in the responses index; reference it from the 500 responses added later

**Checkpoint**: loader, config, problem type and exception exist; stories can start

---

## Phase 3: User Story 1 - A schema mismatch is reported, not hidden (Priority: P1) 🎯 MVP

**Goal**: a missing column or table becomes an actionable, typed 500 problem instead of a bare one.

**Independent Test**: build a `QueryException` around a `PDOException` with `errorInfo` `['42S22', 1054, "Unknown column 'disable_symlinknotowner' in 'INSERT INTO'"]` raised from a `web_domain` insert and assert the response (SQLite cannot raise `42S22`, so this is synthetic).

### Tests for User Story 1 (REQUIRED) ⚠️

- [x] T009 [P] [US1] Feature test `tests/Feature/CompatMissingColumnTest.php`: `42S22` with an `insert into "web_domain"` statement returns 500, `application/problem+json`, `type` ending `#incompatible-ispconfig-schema`, `table` = `web_domain`, `column` = `disable_symlinknotowner`; `42S02` returns 500 naming the table; a qualified column (`web_domain.x`) names table and column; an unparseable statement names the column only; the body contains no SQL text, binding, host or credential (assert on the whole JSON string); any other SQLSTATE (deadlock `40001`, integrity `23000`) stays the generic untyped 500 with `detail` hidden unless `app.debug`
- [x] T010 [P] [US1] In the same test class assert the full exception, including its SQL, is still reported to the log (`Log::spy()` or a log handler assertion) while the response stays clean (US1 scenario 4)

### Implementation for User Story 1

- [x] T011 [US1] Add the `QueryException` branch to `Problem::fromThrowable` in `app/Support/Problem.php`: read SQLSTATE from `$e->errorInfo[0]`, extract the column from the MySQL message (`Unknown column '<col>'`, possibly `tbl.col`), take the table from the qualified column or from the first identifier after `insert into` / `update` / `from` in the server-side SQL, then throw/return the T007 problem; never copy SQL or bindings into the response (research R4)
- [x] T012 [US1] Confirm in `bootstrap/app.php` that the render hook does not suppress reporting of `QueryException` (no `dontReport`); fix only if T010 fails

**Checkpoint**: US1 works alone; the s24 failure now reads "table web_domain is missing column disable_symlinknotowner"

---

## Phase 4: User Story 2 - An incompatible installation is refused before it writes (Priority: P1)

**Goal**: below the minimum schema level, every write is refused before any row exists; reads keep working; the installer refuses to proceed.

**Independent Test**: set the test database's `server.dbversion` below `min_dbversion`; `POST /api/v1/clients` returns 500 and creates no `client` and no `sys_datalog` row; `GET /api/v1/clients` returns 200.

### Tests for User Story 2 (REQUIRED) ⚠️

- [x] T013 [P] [US2] Unit/feature test `tests/Feature/CompatibilityServiceTest.php`: level above, equal and below the minimum; no `server` row and null `dbversion` → `unknown`; database error → `unreachable`; `MIN(dbversion)` over several rows; the `ispc_info` row parsed (version, absent row → null); `version_compare` with the `3.3.1p1` suffix; only a compatible result is cached and an incompatible one is re-read on the next call (FR-010)
- [x] T014 [P] [US2] Feature test `tests/Feature/CompatRefuseWritesTest.php`: below-minimum POST/PUT/DELETE on one endpoint per module (clients, dns, mail, sites) returns 500 naming both levels with `found_dbversion` and `required_dbversion`, and writes no row to the ISPConfig table or `sys_datalog`; GET still 200; no key still 401 (not 500) and non-admin scope unchanged; level undeterminable refuses writes with the "could not be determined" detail; raising the level makes writes work again without a restart

### Implementation for User Story 2

- [x] T015 [US2] Implement `app/Services/CompatibilityService.php` per data-model `CompatReport` (`ok|incompatible|unknown|unreachable`): read `MIN(dbversion)` from `server`, the newest `monitor_data` row with `type = 'ispc_info'` through the existing defensive unserialize in `app/Services/MonitorDataService.php`, compare to `config('api.compat')`, cache only `ok` for `api.compat_cache_ttl`
- [x] T016 [US2] Create `app/Http/Middleware/RefuseWritesWhenIncompatible.php`: for non-safe methods ask `CompatibilityService` and throw `IncompatibleSchemaException` (found/required, or "unknown") when not compatible
- [x] T017 [US2] Register the alias `compat.writes` in `bootstrap/app.php` and add it to the `api.key` group in `routes/api.php` after `api.key` so unauthenticated callers still get 401 first
- [x] T018 [US2] Installer pre-flight in `install.sh`: after the DB credentials are resolved and before anything is written, read `ISPCONFIG_COMPAT` (`sed -n 's/^key=//p'`), read `MIN(dbversion)` from `server` with PDO using the resolved credentials, and exit non-zero with a message naming both levels when below the minimum; re-running the installer is the update path, so one check covers both (research R8)
- [ ] T019 [US2] Verify the pre-flight against a copy of `dbispconfig` with `UPDATE server SET dbversion = dbversion - 6` (quickstart manual step 2): the installer stops before changing anything; record the result in this task's PR description

**Checkpoint**: US1 and US2 work independently; the s24 orphan-client scenario is closed

---

## Phase 5: User Story 4 - Failures are always logged, outside `/usr`, and survive a broken log (Priority: P2)

**Goal**: every unhandled failure is logged to `/var/log/ispconfig-rest/`, falls back to the PHP-FPM error log, and can never break the response.

**Independent Test**: make the log directory read-only and trigger an unhandled exception; the client still receives the problem response and the entry appears in the fallback log.

### Tests for User Story 4 (REQUIRED) ⚠️

- [x] T020 [P] [US4] Feature test `tests/Feature/LoggingResilienceTest.php`: a writable `LOG_PATH` receives one full entry per exception; an unwritable path falls back to the error-log handler and the response is unchanged; both destinations failing leaves the response unchanged and raises no second exception; the log probe reports `ok` / `degraded` for writable / unwritable directories

### Implementation for User Story 4

- [x] T021 [US4] Implement `app/Support/Logging/ResilientLogger.php` (a `via` class): Monolog `FallbackGroupHandler` of `RotatingFileHandler(LOG_PATH, 14 days)` then `ErrorLogHandler`, wrapped so any handler exception is swallowed (FR-020); add the channel to `config/logging.php` with `LOG_PATH` defaulting to `storage/logs/ispconfig-rest.log` and make it the default for the application
- [x] T022 [P] [US4] Implement the log probe `app/Support/Logging/LogHealth.php`: the log directory exists and is writable, an existing log file is writable, a fallback used in the current request counts as `degraded`; return a `CheckResult` state (`ok|degraded`) without writing
- [x] T023 [US4] `install.sh`: create `/var/log/ispconfig-rest` mode 0750 owned by the run user (the existing `$RUN_USER`), write `LOG_PATH=/var/log/ispconfig-rest/ispconfig-rest.log` into the generated `.env`; rotation is the application's 14-day daily rotation (research R5)

**Checkpoint**: failures leave a trace outside `/usr`, and logging trouble cannot change a response

---

## Phase 6: User Story 3 - Probes and a status report (Priority: P2)

**Goal**: Kubernetes-style `/livez` and `/readyz`, an administrator `GET /api/v1/status`, and `ispconfig-rest doctor`, all reading the same checks.

**Independent Test**: with a compatible database `/livez` and `/readyz` return 200; lower the schema level: `/livez` stays 200, `/readyz` returns 503, and the status report shows both levels; `doctor` prints the failure and exits non-zero.

### Contract first (Principle I)

- [ ] T024 [P] [US3] Author `api/modules/system/status.yaml` (`GET /status`, admin key, 200/401/403) and the `Status`, `Readiness`, `CheckState` schemas in `api/components/schemas/` from `contracts/api-probes-status.yaml`; register in `api/modules/system/_index.yaml`, `api/components/schemas/_index.yaml` and `api/openapi.yaml`; document `/livez` and `/readyz` (`security: []`, `servers: [{url: /}]`) in `api/openapi.yaml`

### Tests for User Story 3 (REQUIRED) ⚠️

- [ ] T025 [P] [US3] Feature test `tests/Feature/ProbeTest.php`: `/livez` is 200 with the database failing; `/readyz` is 200 when healthy with only per-check states (assert no version, level or hostname in the body); 503 with `schema` failed below the minimum; 503 with `database` failed; `logging: degraded` keeps 200; the response carries `Cache-Control: no-store`; no session cookie is set
- [ ] T026 [P] [US3] Feature test `tests/Feature/StatusEndpointTest.php`: 401 without a key, 403 with a non-admin key, 200 with an admin key returning `compatible`, `ispconfig.{version,dbversion,required_dbversion,minimum_version}` and `logging.state`; `GET /` stays minimal and exposes no ISPConfig version or check result (FR-016)
- [ ] T027 [P] [US3] Feature test `tests/Feature/DoctorCommandTest.php`: `ispconfig:doctor` prints one line per check, exits 0 when no check failed (degraded logging included), exits 1 on a failed check, and its log-directory write probe reports an unwritable directory

### Implementation for User Story 3

- [ ] T028 [US3] Implement `app/Services/HealthCheckService.php`: `database` (`SELECT 1`), `schema` (from `CompatibilityService`; `unknown` counts as failed), `migrations` (migration files versus the `migrations` table through the Migrator), `logging` (from `LogHealth`, never failing readiness); shared by probes, status and doctor
- [ ] T029 [US3] Implement `app/Http/Controllers/ProbeController.php` (`livez`, `readyz`) and create `routes/ops.php`; load it through the `then:` hook of `withRouting` in `bootstrap/app.php` with no middleware group; leave `/up` untouched
- [ ] T030 [US3] Implement `app/Http/Controllers/Api/V1/StatusController.php` and register `GET status` in `routes/api/system.php` inside the `scope.admin` group, before any parameterized route (ordering rule)
- [ ] T031 [US3] Implement `app/Console/Commands/Doctor.php` (`ispconfig:doctor`, adds the log-directory write probe) and the `doctor` subcommand in `bin/ispconfig-rest` (`need_root`, runs as the run user like `key:create`)
- [ ] T032 [US3] Verify in Swagger UI (`/api/documentation`) that `/api/v1/status` renders and matches the YAML, and that `GET /api/spec` still serves the multi-file spec (the existing Swagger spec test)

**Checkpoint**: an orchestrator and an operator can see why the API refuses writes without reading a log or enabling debug mode

---

## Phase 7: User Story 5 - Code that needs a newer schema fails CI (Priority: P3)

**Goal**: CI derives, from the real ISPConfig schema, the level the written columns need and fails when it exceeds the declared minimum.

**Independent Test**: make a model write a column from a patch above `min_dbversion`; CI fails naming the column and patch; raising the minimum makes it pass.

### Tests for User Story 5 (REQUIRED) ⚠️

- [ ] T033 [P] [US5] Unit test `tests/Unit/CheckCompatSchemaTest.php` with small fixture SQL under `tests/fixtures/ispconfig-schema/` (a base `ispconfig3.sql` and `incremental/upd_0100.sql`, `upd_0101.sql`): a column from patch 101 with `min_dbversion=100` fails naming column and patch; with `101` it passes; a written column in no schema fails; an unclassifiable statement is printed as a warning, not skipped silently

### Implementation for User Story 5

- [ ] T034 [US5] Add the collector to `tests/TestCase.php`: when `COMPAT_COLLECT=<path>` is set, register `DB::listen` in `setUp()`, extract `(table, column)` pairs from `insert` and `update` statements (ignore `api_*` and framework tables) and write the unique set as JSON at shutdown
- [ ] T035 [US5] Implement `scripts/check-compat-schema.php`: read the collected JSON, parse `install/sql/ispconfig3.sql` (columns per table) and `install/sql/incremental/upd_NNNN.sql` (`ADD COLUMN`, `CREATE TABLE`) from a directory argument, compute each column's introducing patch, print the required level and fail if it exceeds `min_dbversion` or a column exists in no schema (research R9)
- [ ] T036 [US5] Compute the sha256 of `ISPConfig-3.3.1p1.tar.gz` from ispconfig.org and add `schema_tarball_sha256` to `ISPCONFIG_COMPAT`; add the CI steps to `.github/workflows/tests.yml`: run the suite with `COMPAT_COLLECT=build/written-columns.json`, download the tarball, verify the sha256, extract `install/sql/`, run `scripts/check-compat-schema.php`
- [ ] T037 [US5] Verify the negative case once on a throwaway branch (a write to a patch-101 column fails CI naming it) and record the result in the PR description

**Checkpoint**: the declared minimum cannot silently fall behind the code

---

## Phase 8: Polish & Cross-Cutting Concerns

- [ ] T038 [P] Update `README.md`: the Requirements line points at `ISPCONFIG_COMPAT` instead of repeating versions; document `/livez`, `/readyz`, `GET /api/v1/status`, `ispconfig-rest doctor`, the `incompatible-ispconfig-schema` problem and the log location `/var/log/ispconfig-rest/`
- [ ] T039 Amend the constitution through `/speckit-constitution`: record the unauthenticated probe routes, and `/readyz` answering 503, as the documented exception to Principles IV and V (plan Complexity Tracking)
- [ ] T040 Run the full suite (`php artisan test`, container) and the quickstart manual steps on a staging host; confirm no ISPConfig table or `sys_datalog` row is written by any new code path
- [ ] T041 Record the Salt follow-ups in an issue (separate repository, no code here): create `/var/log/ispconfig-rest` for the run user, run `ispconfig-rest doctor` after any `dbispconfig` restore, and compare `.ispconfig-version` with `min_version`; multi-server stays a TODO

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (1)** → **Foundational (2)** → all user stories
- **US1** (P1) and **US2** (P1) are independent of each other; US2's `CompatibilityService` reuses the T007 exception
- **US4** (logging) is independent of US1–US3; **US3** needs `LogHealth` from US4 (T022) and `CompatibilityService` from US2 (T015)
- **US5** needs only the loader (T003) and a stable test suite
- **Polish** after the stories it documents

### Suggested PR slices onto `core`

1. US1 + US4 (reporting and logging): T009–T012, T020–T023
2. US2 (compatibility): T013–T019
3. US3 (probes, status, doctor): T024–T032
4. US5 (CI): T033–T037
5. Polish: T038–T041

Foundational (T001–T008) ships with slice 1.

### Within Each User Story

- Tests first, and they must fail before the implementation
- Contract YAML before controller work (Principle I)
- Service before middleware or controller; controller before routes
- `bootstrap/app.php`, `install.sh`, `config/api.php`, `routes/api.php` are single shared files: edit them sequentially across stories

### Parallel Opportunities

- T002, T004, T008 after T001/T003
- T009 and T010 together; T013 and T014 together; T020 and T022 together
- T024–T027 (contract and the three test files) together
- US4 and US2 in parallel after Foundational, by different authors

---

## Implementation Strategy

### MVP First (User Story 1 only)

1. Phase 1 and Phase 2
2. Phase 3 (US1): the actionable typed 500 for a missing column or table
3. **STOP and VALIDATE**: the quickstart's missing-column check, then ship slice 1 (with US4)

### Incremental Delivery

1. US1 + US4 → the failure is visible and logged
2. US2 → the failure is prevented before any write
3. US3 → orchestrators and operators can see it
4. US5 → the declared minimum stays honest

---

## Notes

- Nothing in this feature writes an ISPConfig table; a task that does violates Principle II
- Commit after each task or logical group, with semantic prefixes (`feat:`, `fix:`, `test:`, `docs:`, `ci:`)
- Avoid vague tasks, same-file conflicts, and any endpoint that is not in the YAML contract
