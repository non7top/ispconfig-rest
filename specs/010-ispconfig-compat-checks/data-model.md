# Data Model: ISPConfig Compatibility Checks and Failure Reporting

No ISPConfig table is written and no migration is added. The feature reads two ISPConfig tables and one framework table, and introduces one repository file and a few derived value objects.

## Stored inputs (read-only)

| Source | Fields used | Notes |
|--------|-------------|-------|
| `server` (ISPConfig) | `dbversion` (int) | Schema level; `MIN` over rows, one row on supported installs |
| `monitor_data` (ISPConfig) | `type = 'ispc_info'`, `data` (serialized), `created` | Newest row per R2; `data.version` is `ISPC_APP_VERSION`; informational |
| `migrations` (Laravel) | `migration` | Compared with the migration files for the `migrations` check |

## Repository file: `ISPCONFIG_COMPAT`

| Key | Type | Rule |
|-----|------|------|
| `min_dbversion` | int ≥ 1 | Required. The lowest `server.dbversion` the code supports |
| `min_version` | string | Required. Informational, human-readable (`3.3.1p1`) |
| `tested_version` | string | Required. The release whose schema CI checks against |
| `schema_tarball_sha256` | 64 hex chars | Optional. Pin for the CI download |

Lines starting with `#` and blank lines are ignored. A missing file, a missing required key, a non-integer `min_dbversion` or an unknown key is an error naming the file (FR-006).

## Derived value objects (no persistence)

**CompatReport**

| Field | Type | Meaning |
|-------|------|---------|
| `state` | `ok` \| `incompatible` \| `unknown` \| `unreachable` | `unknown`: level unreadable (no `server` row, null `dbversion`); `unreachable`: database error |
| `dbversion` | int \| null | Found schema level |
| `required_dbversion` | int | From `ISPCONFIG_COMPAT` |
| `version` | string \| null | ISPConfig version, `null` when no `ispc_info` row |
| `compatible` | bool | `state === ok` |

Transitions: only `ok` is cached (TTL `api.compat.cache_ttl`); every other state is recomputed on the next request, so the report moves to `ok` as soon as ISPConfig's updater raises `dbversion`.

**CheckResult** (readiness, status, doctor)

| Field | Type |
|-------|------|
| `name` | `database` \| `schema` \| `migrations` \| `logging` |
| `state` | `ok` \| `failed` \| `degraded` \| `unknown` |

Readiness is `ok` iff no check is `failed`. `degraded` (logging only) and `unknown` do not fail readiness, except `schema = unknown`, which is treated as `failed` for readiness and for write refusal (FR-009).

**WrittenColumn** (CI only): `(table, column)` pairs from `insert`/`update` statements during the test run, serialized as JSON to the `COMPAT_COLLECT` path; the checker maps each to its introducing patch.

## Problem members

`incompatible-ispconfig-schema` problem (500) adds members, never SQL:

| Case | Members |
|------|---------|
| Missing column/table (US1) | `table` (when determinable), `column` (for `42S22`) |
| Level below minimum (US2) | `found_dbversion`, `required_dbversion` |
| Level undeterminable (FR-009) | `required_dbversion`, `found_dbversion` omitted |
