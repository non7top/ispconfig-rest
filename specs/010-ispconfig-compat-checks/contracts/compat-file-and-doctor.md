# Contract: `ISPCONFIG_COMPAT` and `ispconfig-rest doctor`

## `ISPCONFIG_COMPAT` (repository root, beside `VERSION`)

```
# Lowest server.dbversion the code supports (the ISPConfig updater's schema level).
min_dbversion=100
# Human-readable release that level belongs to.
min_version=3.3.1p1
# Release whose schema CI checks written columns against.
tested_version=3.3.1p1
# Optional: sha256 of ISPConfig-<tested_version>.tar.gz used by CI.
schema_tarball_sha256=<64 hex>
```

Readers: `App\Support\IspconfigCompat::load()` (PHP), `install.sh` and CI (`sed -n 's/^key=//p'`). A missing file, a missing required key, a non-integer `min_dbversion` or an unknown key fails with a message naming `ISPCONFIG_COMPAT`.

Raising `min_dbversion` is part of any change that starts writing a column from a newer patch; the CI check (research R9) fails until it is.

## `ispconfig-rest doctor`

Runs as the run user (`bin/ispconfig-rest doctor` → `artisan ispconfig:doctor`). One line per check, then a summary:

```
database    ok
schema      failed   found dbversion 98, requires 100 (ISPConfig 3.3.1p1)
migrations  ok
logging     degraded /var/log/ispconfig-rest is not writable
```

Exit code 0 when no check is `failed`; 1 otherwise. `logging: degraded` alone exits 0 (it matches readiness) but is printed. `doctor` additionally attempts a write probe in the log directory, which readiness does not.

## Problem type `urn:ispconfig-rest:problem:incompatible-ispconfig-schema`

HTTP 503, `application/problem+json`. Members per `data-model.md`. `detail` examples:

- `The ISPConfig database schema is older than this API expects: table web_domain is missing column disable_symlinknotowner. Run ISPConfig's updater.`
- `ISPConfig schema level 98 is below the required 100. Run ISPConfig's updater.`

Never present: SQL text, bound values, hostnames, credentials.
