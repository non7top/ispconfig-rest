# Implementation Plan: Prebuilt Debian Package and Salt-callable Migrations

**Branch**: `060-plan` (stacked on `060-prebuilt-deb-package`) | **Date**: 2026-10-11 | **Spec**: [spec.md](spec.md)
**Input**: Feature specification from `/specs/060-prebuilt-deb-package/spec.md`

## Summary

Ship the API as a signed, architecture-independent `.deb` that a release workflow builds from a calendar-version tag, and give Salt one idempotent command for the privileged migration. The plan: an artisan command `ispconfig:migrate [--check]` with the manager subcommand `ispconfig-rest migrate` on top (one implementation, also used by `install.sh`); the environment file read from `/etc/ispconfig-rest/` when present; a package layout (`/usr/share/ispconfig-rest`, a system user, `/var/log` and `/var/cache` directories, a boot-time tmpfiles declaration) built by a script and tested in a container; and a tag-triggered workflow that checks `VERSION`, builds, tests, signs with `apt-cosign` and publishes a flat apt repository on the `apt-repo` branch plus a GitHub release asset.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 12) for the command; POSIX shell for the manager, package scripts and build; GitHub Actions YAML
**Primary Dependencies**: laravel/framework `Migrator` (already used by `HealthCheckService`); `dpkg-deb`, `dpkg-scanpackages`; the `non7top/apt-cosign` actions `fetch-apt-cosign-sign` and `publish-apt-repo`; no new Composer package
**Storage**: the API's own `api_keys` and `migrations` tables in `dbispconfig` (unchanged); reads `server.dbversion`; nothing else is written
**Testing**: PHPUnit feature tests for the command on SQLite (exit codes, idempotence, refusal, empty-table message); shell tests with stubbed PHP for the manager; a container test (`scripts/test-deb.sh`, `php:8.3-cli` image) that installs the built package; `act` for the workflow's build job where feasible
**Target Platform**: Debian or Ubuntu hosts beside a single-server ISPConfig 3.3 install, run by php-fpm; multi-server is a TODO of spec 010
**Project Type**: Contract-first REST API plus deployment tooling; no REST contract changes
**Performance Goals**: `migrate --check` is one read of the `migrations` table and the migration file list; no cost on the request path
**Constraints**: nothing is written under the packaged tree at runtime; the package writes no configuration and runs no migration; a password is never printed; the command never touches an ISPConfig table
**Scale/Scope**: 1 artisan command, 1 manager subcommand, a few lines in `bootstrap/app.php`, 5 package files, 2 scripts, 1 workflow

## Constitution Check

*GATE: passes. Re-checked after Phase 1 design: unchanged.*

- [x] **Spec-first (I)**: no REST path, parameter or response changes; the only new public surface is a CLI contract, written first in [`contracts/migrate-command.md`](contracts/migrate-command.md) and [`contracts/package-layout.md`](contracts/package-layout.md)
- [x] **Datalog-only writes (II)**: nothing is written to an ISPConfig table; the command creates only the API's own `api_keys` and the Laravel `migrations` table and refuses below the supported schema level; the API still never applies ISPConfig patches
- [x] **Legacy parity (III)**: not applicable (deployment tooling); the schema-level refusal reuses spec 010's `CompatibilityService`
- [x] **Route discipline (IV)**: no routes added
- [x] **HTTP contract (V)**: no change; `GET /` keeps its shape and reports the package version through `VERSION`
- [x] **No schema changes**: no new table and no change to `api_keys`

## Project Structure

### Documentation (this feature)

```text
specs/060-prebuilt-deb-package/
├── plan.md                    # This file
├── research.md                # Phase 0: decisions R1–R9
├── data-model.md              # Phase 1: release, package, runtime layout, exit codes
├── quickstart.md              # Phase 1: how to verify
├── contracts/
│   ├── migrate-command.md     # command, options, exit codes, output
│   └── package-layout.md      # files, user, directories, scripts, workflow outputs
├── checklists/requirements.md
└── tasks.md                   # Phase 2 (/speckit-tasks)
```

### Source Code (repository root)

```text
app/Console/Commands/Migrate.php           # new: ispconfig:migrate [--check]
bin/ispconfig-rest                         # migrate subcommand, package defaults, update guard
install.sh                                 # step "Creating the api_keys table" calls the command
bootstrap/app.php                          # environment path outside the tree when present

packaging/deb/                             # new
├── control.in                             # package metadata, version substituted
├── postinst                               # system user and directories
├── postrm                                 # purge removes the cache directory
└── ispconfig-rest.tmpfiles                # boot-time directories

scripts/build-deb.sh                       # builds the tree (composer --no-dev) and the .deb
scripts/test-deb.sh                        # installs the .deb in a container and checks it
.github/workflows/release.yml              # tag -> check, build, test, sign, publish
tests/Feature/MigrateCommandTest.php       # new
```

**Structure Decision**: the command lives with the other console commands and reuses `HealthCheckService`'s pending-migration logic; packaging files sit under `packaging/deb/` and are assembled by one script that both CI and local runs use, in a throwaway container (global rule).

## Phases

1. **Slice 1 — migrate command** (P1, story 2): command, manager subcommand, `install.sh` integration, tests.
2. **Slice 2 — environment path** (P2, story 4): `/etc/ispconfig-rest/.env` when present; verification with a read-only tree.
3. **Slice 3 — package** (P1/P2, stories 1 and 3): build script, package files, manager package defaults and `update` guard, container test.
4. **Slice 4 — release workflow** (P3, story 5): tag workflow, `VERSION` check, signing and publishing.

## Complexity Tracking

| Item | Why needed | Simpler alternative rejected because |
|------|------------|--------------------------------------|
| Own lock around migrations | Two Salt runs must not both apply | Laravel's `--isolated` needs a cache lock store the CLI user may not have; a MySQL `GET_LOCK` needs none |
