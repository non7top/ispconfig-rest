# Implementation Plan: ISPConfig Compatibility Checks and Failure Reporting

**Branch**: `010-ispconfig-compat-checks` | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/010-ispconfig-compat-checks/spec.md`

## Summary

Turn the s24 failure (a restored, older `dbispconfig` schema produced a bare, unlogged 500 and orphan rows) into a visible, refused and checkable condition. The plan: map a missing column or table to a typed 503 problem; declare the minimum ISPConfig schema level in one file, `ISPCONFIG_COMPAT`, read by the app, the installer, a `doctor` command and CI; refuse writes (not reads) below it before any row is written; add Kubernetes-style `/livez` and `/readyz` probes and an administrator-only `/api/v1/status`; log failures outside `/usr` with a file-then-PHP-FPM-log fallback that can never break the response; and add a CI step that derives, from the real ISPConfig schema, the level the code actually needs. Decisions and rejected alternatives are in [research.md](research.md).

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12), as the stack rows of this project; CI matrix 8.3, 8.4, 8.5
**Primary Dependencies**: laravel/framework ^12 (Cache, Log with Monolog `FallbackGroupHandler`/`RotatingFileHandler`, Migrator); no new package
**Storage**: reads `server.dbversion`, `monitor_data` (`ispc_info`) and Laravel's `migrations`; no write to any ISPConfig table, no migration
**Testing**: PHPUnit feature and unit tests per endpoint and component (constitution v2); synthetic `QueryException` for `42S22`/`42S02` because SQLite cannot raise them; CI step with a pinned ISPConfig tarball
**Target Platform**: Linux host beside a single-server ISPConfig 3.3 install, run by php-fpm; multi-server is a TODO in the spec
**Project Type**: Contract-first REST API (monolith) plus `install.sh`, `bin/ispconfig-rest` and a CI script
**Performance Goals**: at most one indexed `server` query per cache miss; compatible results cached for `API_COMPAT_CACHE_TTL` (default 60 s); probes do no work beyond their checks
**Constraints**: the error path never depends on logging; response bodies never contain SQL, bindings, hosts or credentials; the API never applies ISPConfig schema patches
**Scale/Scope**: 3 routes, 1 route middleware, 1 service, 1 loader, 1 artisan command, 1 log driver, 1 repository file, installer pre-flight and log directory, 1 CI step and checker script

## Constitution Check

*GATE: passes with two documented exceptions (Complexity Tracking). Re-checked after Phase 1 design: unchanged.*

- [x] **Spec-first (I)**: contracts are authored first in [`contracts/api-probes-status.yaml`](contracts/api-probes-status.yaml) and split into `api/modules/system/status.yaml`, `api/openapi.yaml` and `api/components/` as the first implementation step; no URL or body shape exists only in PHP
- [x] **Datalog-only writes (II)**: the feature writes nothing to ISPConfig tables; it reads `server` and `monitor_data`. The API never applies schema patches
- [x] **Legacy parity (III)**: the schema level and version comparison mirror ISPConfig's own `update.lib.php` (`dbversion` plus `upd_NNNN`) and `version_compare`; recorded in the spec's Parity section
- [~] **Route discipline (IV)**: `/api/v1/status` goes in the authenticated `scope.admin` group of `routes/api/system.php`; the unauthenticated probes are an exception (Complexity Tracking)
- [~] **HTTP contract (V)**: errors are RFC 9457 problem+json and the probes' non-problem bodies are operational JSON; 503 is not in the constitution's status list (Complexity Tracking)
- [x] **No schema changes**: no migration against ISPConfig tables; no new API table

## Project Structure

### Documentation (this feature)

```text
specs/010-ispconfig-compat-checks/
├── plan.md                              # This file
├── research.md                          # Phase 0: decisions R1–R11
├── data-model.md                        # Phase 1: stored inputs, ISPCONFIG_COMPAT, value objects
├── quickstart.md                        # Phase 1: how to verify
├── contracts/
│   ├── api-probes-status.yaml           # OpenAPI draft: /livez, /readyz, /api/v1/status, problem component
│   └── compat-file-and-doctor.md        # ISPCONFIG_COMPAT format, doctor output, problem type
├── checklists/requirements.md
└── tasks.md                             # Phase 2 (/speckit-tasks) — not created here
```

### Source Code (repository root)

```text
ISPCONFIG_COMPAT                          # new: min_dbversion, min_version, tested_version[, schema_tarball_sha256]

api/
├── openapi.yaml                          # register /api/v1/status, /livez, /readyz
├── modules/system/status.yaml            # new
└── components/{schemas,responses}/       # Status, Readiness, CheckState, IncompatibleSchema

app/
├── Support/IspconfigCompat.php           # new: parse and validate ISPCONFIG_COMPAT
├── Support/Problem.php                   # QueryException branch; IncompatibleSchema helper
├── Support/ProblemType.php               # new constant INCOMPATIBLE_SCHEMA (catalogue from spec 023)
├── Support/Logging/ResilientLogger.php   # new: Monolog FallbackGroupHandler, never throws
├── Services/CompatibilityService.php     # new: schema level, version string, cache, CompatReport
├── Services/HealthCheckService.php       # new: database/schema/migrations/logging CheckResults
├── Exceptions/IncompatibleSchemaException.php  # new: carries levels or table/column
├── Http/Middleware/RefuseWritesWhenIncompatible.php   # new: alias compat.writes
├── Http/Controllers/Api/V1/StatusController.php       # new
├── Http/Controllers/ProbeController.php               # new: livez, readyz
└── Console/Commands/Doctor.php           # new: ispconfig:doctor

bootstrap/app.php                         # alias + append middleware; then: ops routes
docs/problems.md                          # incompatible-ispconfig-schema entry
config/api.php                            # compat block (PHP loader result, cache ttl)
config/logging.php                        # resilient channel, LOG_PATH
.env.example                              # LOG_PATH, API_COMPAT_CACHE_TTL
routes/ops.php                            # new: /livez, /readyz, no middleware group
routes/api/system.php                     # GET status inside scope.admin

bin/ispconfig-rest                        # doctor subcommand
install.sh                                # pre-flight, LOG_PATH, log directory
scripts/check-compat-schema.php           # new: CI checker (research R9)
.github/workflows/tests.yml               # collector run + checker step

tests/TestCase.php                        # COMPAT_COLLECT DB::listen collector
tests/Feature/{CompatMissingColumnTest,CompatRefuseWritesTest,ProbeTest,StatusEndpointTest,LoggingResilienceTest}.php
tests/Unit/{IspconfigCompatTest,CheckCompatSchemaTest}.php
```

**Structure Decision**: all runtime logic sits in two services (`CompatibilityService`, `HealthCheckService`) shared by the middleware, the controllers and `doctor`, so the three surfaces cannot disagree. Routes follow the ordering rule; `status` is a literal path and cannot be shadowed.

## Legacy Research (Phase 0 focus)

Done; see [research.md](research.md) R1–R2: `install/lib/update.lib.php` (`dbversion` and incremental patch loading), `server/lib/classes/cron.d/100-monitor_ispconfig_version.inc.php` (`ispc_info`), and `install/sql/incremental/upd_NNNN.sql` as the source for R9.

## Delivery Order (suggested PR slices onto `core`)

1. **Reporting and logging** (US1, US4): `Problem` `QueryException` branch, `IncompatibleSchemaException`, resilient logger, `LOG_PATH`, installer log directory. Smallest fix, ships first.
2. **Compatibility** (US2): `ISPCONFIG_COMPAT`, loader, `CompatibilityService`, write-refusal middleware, installer pre-flight.
3. **Probes, status, doctor** (US3): `HealthCheckService`, routes, controller, command.
4. **CI schema check** (US5): collector, checker script, workflow step.

Slice 1 builds on the problem-type catalogue (spec 023, PR #16); PR #10 (`config/api.php` reading `VERSION`) is already merged.

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| `/livez` and `/readyz` are unauthenticated and outside the versioned `api/v1` group (Principle IV) | Orchestrator and load-balancer probes cannot present an API key and must not depend on the database for liveness | Putting them in `api/v1` behind `api.key` would make a probe depend on the key store being healthy and would 401 every probe. They expose per-check states only, never versions or levels |
| HTTP 503 is not in Principle V's status list | A missing schema or incompatible ISPConfig is a dependency being unavailable for this request, not a client error (4xx) nor a server bug (500); orchestrators key off 503 | 500 is what failed on s24 and is what makes the failure undiagnosable; 409/422 would blame the caller. A one-line constitution amendment adding 503 (dependency or compatibility unavailable) goes with the implementation |
