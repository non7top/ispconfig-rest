# Tasks: Prebuilt Debian Package and Salt-callable Migrations

**Input**: Design documents from `/specs/060-prebuilt-deb-package/`
**Prerequisites**: plan.md, spec.md, research.md, contracts/

Format: `- [ ] T### [P?] [US#] Description with file path`. `[P]` = parallel (different files, no dependency).

## Phase 1: Setup

- [x] T001 Confirm the fork's `core` ruleset does not forbid force-pushing a branch named `apt-repo`; record the result in `specs/060-prebuilt-deb-package/research.md` (R9)

## Phase 2: Foundational

- [x] T002 Add `.env`-independent test helpers for the command: a fixture that creates the `server` table at a given `dbversion` (reuse `tests/Support/ServerSchema.php`) in `tests/Feature/MigrateCommandTest.php`

## Phase 3: User Story 2 - idempotent migration command (P1) — slice 1

**Independent test**: against a database without `api_keys`: `ispconfig:migrate` creates it and exits 0; a second run exits 0 with `up to date`; `--check` exits 2 then 0.

- [x] T003 [US2] Test first: pending → applied → up to date; `--check` exit codes 2/0/1; refusal below `min_dbversion` before any DDL; `api_keys created empty` line; password never in output; partial failure resumes — `tests/Feature/MigrateCommandTest.php`
- [x] T004 [US2] Implement `app/Console/Commands/Migrate.php` (`ispconfig:migrate {--check}`): reuse the pending-migration logic of `app/Services/HealthCheckService.php`, `CompatibilityService` refusal, MySQL `GET_LOCK` skipped on other drivers, exit codes 0/1/2
- [x] T005 [US2] Add `migrate` and `migrate --check` to `bin/ispconfig-rest` (root only; privileged login from `ISPC_REST_DB_ADMIN_USER`/`ISPC_REST_DB_ADMIN_PASS`; run as OS root only for root with no password, with `LOG_CHANNEL=stderr CACHE_STORE=array`; otherwise as the service user); update its header and README
- [x] T006 [US2] Replace `migrate_privileged` in `install.sh` with a call to `ispconfig:migrate` under the same environment rules; stub-test the manager and installer branches in `tests/Unit/` or a documented script run

## Phase 4: User Story 4 - configuration outside the tree (P2) — slice 2

**Independent test**: with the tree mounted read-only and `.env` only in `/etc/ispconfig-rest/`, requests, cache and log writes succeed.

- [x] T007 [P] [US4] Test first: a unit test that the application uses `/etc/ispconfig-rest/.env`'s directory when the file exists and the tree otherwise — `tests/Unit/EnvironmentPathTest.php` (path made injectable for the test)
- [x] T008 [US4] Implement in `bootstrap/app.php`: `useEnvironmentPath('/etc/ispconfig-rest')` when `/etc/ispconfig-rest/.env` exists
- [x] T009 [US4] Verify with a read-only tree in a container (as done for spec 060 research R7) and record the command in `quickstart.md`

## Phase 5: User Stories 1 and 3 - package (P1/P2) — slice 3

**Independent test**: build the package, install it in `php:8.3-cli`, check user, directories, modes and `ispconfig-rest version`.

- [x] T010 [P] [US3] Package files `packaging/deb/control.in`, `postinst`, `postrm`, `ispconfig-rest.tmpfiles` per `contracts/package-layout.md` (idempotent, no config, no migration)
- [x] T011 [US1] `scripts/build-deb.sh <version>`: build the tree with `composer install --no-dev --classmap-authoritative --no-scripts` in a container, assemble `/usr/share/ispconfig-rest`, the `PACKAGED` marker, the manager in `/usr/bin`, and call `dpkg-deb`
- [x] T012 [US1] `scripts/test-deb.sh`: install the built package in `php:8.3-cli` as root, assert user, group, directories and modes, a second install and a deleted directory are repaired, the tree contains no tests or specs, `ispconfig-rest version` prints the version, and the application boots with the tree read-only
- [x] T013 [US1] Manager package defaults and `update` guard in `bin/ispconfig-rest` (`PACKAGED` marker, `RUN_USER=ispconfig-rest`, optional `install.conf` override, `update` message); extend its stub test
- [x] T014 [US1] README: install from the apt source, the Salt call order (`apt` → write `.env` → `ispconfig-rest migrate` → `doctor`)

## Phase 6: User Story 5 - release workflow (P3) — slice 4

**Independent test**: a test tag on a fork produces a signed repository and a release asset.

- [x] T015 [US5] `.github/workflows/release.yml`: trigger on `[0-9]*` tags; fail when `VERSION` differs from the tag; build; run `scripts/test-deb.sh`; sign and publish with the apt-cosign actions (`id-token: write`, `contents: write`); GitHub release with the `.deb` and sha256
- [x] T016 [US5] Run the workflow's build and test jobs locally with `act` where feasible and record what could not run (the `publish` job needs a real OIDC token and cannot run under `act`; the `build` job passed, run from a plain clone because `act` cannot resolve a git worktree); document the client source line and the `apt-cosign` policy in the README

## Phase 7: Polish

- [x] T017 Full test suite and Pint on the final stack; update `ispconfig-rest-parked-ideas`-style notes only in the PR descriptions
- [ ] T018 First real run: tag the first release after the stack merges and install it on one node (open, like spec 010's T019)

## Dependencies

T003 → T004 → T005 → T006. T007 → T008 → T009. T010, T011 → T012 → T013 → T014. T015 needs T011, T012. Slices merge in order 1, 2, 3, 4; slice 3 needs slice 2 (the package relies on `/etc/ispconfig-rest/.env`).
