# Feature Specification: Prebuilt Debian Package and Salt-callable Migrations

**Feature Branch**: `060-prebuilt-deb-package`
**Created**: 2026-10-11
**Status**: Draft
**Module**: cross-cutting (deployment)
**Input**: User description: "Ship the API as a prebuilt, signed .deb built in CI on a release tag, with no composer or git on the target; the package manages the system user, the /var directories and their permissions; configuration stays with Salt; the migrations are packaged as an idempotent command Salt can call."

## Background

Today `install.sh` clones the repository and runs composer on the target host. That needs git, composer, its PHP extensions (issue #31: `phar`), Packagist access and a matching PHP at install time (issue #33). The installer also mixes three jobs: fetching code, writing configuration, and running the privileged migration that creates `api_keys` in `dbispconfig`. The fork has no release tags after `v1.0.0-rc.3`, so a deployed version is a commit sha, and `GET /` reports `VERSION`, which was `1.0.0-rc.3` on rc.3 and on `core` alike (fixed by the calendar version `2026.10.11`, PR #30).

Salt re-runs `install.sh` from the pinned commit whenever the pin changes; it neither creates `/var/log/ispconfig-rest` and `/var/cache/ispconfig-rest` itself nor runs `ispconfig-rest doctor`. The release signing tool `apt-cosign` (`non7top/apt-cosign`) verifies a Sigstore bundle for every file apt fetches, bound to a GitHub Actions identity.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Install a pinned, verified release without composer or git (Priority: P1)

An operator or a Salt state installs one named release (a calendar-version tag such as `2026.10.11`) as a Debian package. The package contains the application with its `vendor/` tree already built, so the host needs PHP and nothing else to build or fetch dependencies. apt verifies the package against the release workflow's Sigstore identity before it is installed.

**Why this priority**: It removes the composer, `phar`, Packagist and multiple-PHP problems from every node and makes "which version is deployed" a package version.

**Independent Test**: Build the package in CI from a tag, install it in a clean container that has PHP but no git or composer, and run `ispconfig-rest version` and `php artisan --version` from the installed tree. Expect the tag as the version and no network access during install.

**Acceptance Scenarios**:

1. **Given** a pushed tag whose name equals the `VERSION` file, **When** the release workflow runs, **Then** it publishes a signed package whose version is the tag.
2. **Given** a tag that differs from `VERSION`, **When** the release workflow runs, **Then** it fails and publishes nothing.
3. **Given** a host without git and composer, **When** the package is installed, **Then** the application files are present and nothing is fetched at install time.
4. **Given** a package whose signature does not match the release identity, **When** apt fetches it through the verifying transport, **Then** it is refused.
5. **Given** the installed package, **When** `GET /` is called, **Then** it reports the package's version.

---

### User Story 2 - An idempotent migration command Salt can call (Priority: P1)

The package ships `ispconfig-rest migrate`, a command that applies the API's own pending migrations (today only `api_keys`) using a privileged database login that Salt provides, and is safe to run on every Salt run. Salt calls it after a package change and uses its exit code, without scripting around `artisan`.

**Why this priority**: The migration needs DDL rights the runtime user lacks, so it cannot run on first use. It must be a deliberate, scriptable step that does nothing when nothing is pending; otherwise Salt cannot call it unconditionally.

**Independent Test**: Against a database without `api_keys`, run the command: the table is created and the command exits 0 reporting the applied migration. Run it again: nothing changes and it exits 0 reporting "up to date". Run it with `--check` before and after: exit 2 (pending), then exit 0.

**Acceptance Scenarios**:

1. **Given** pending migrations, **When** `ispconfig-rest migrate` runs, **Then** they are applied, the command reports each one and exits 0.
2. **Given** no pending migrations, **When** it runs again, **Then** nothing is written, it reports "up to date" and exits 0 (idempotent).
3. **Given** `--check`, **When** migrations are pending, **Then** nothing is written and the command exits 2; with none pending it exits 0; on any error it exits 1.
4. **Given** the ISPConfig schema level below the supported minimum, **When** it runs, **Then** it refuses before any DDL and exits 1 naming both levels.
5. **Given** a run that failed after some migrations, **When** it is run again, **Then** it applies the remaining ones and never repeats a completed one.
6. **Given** `dbispconfig` was restored from a backup that predates `api_keys`, **When** the command runs, **Then** it creates the table and states plainly that it was created empty, so existing keys must be minted again.
7. **Given** the privileged login is missing or wrong, **When** it runs, **Then** it exits 1 with a message that names which login was tried and never prints a password.
8. **Given** two runs started together, **When** both reach the database, **Then** only one applies migrations and the other waits or reports "already running"; the end state is the same as one run.

---

### User Story 3 - The package owns the system user, the /var directories and their permissions (Priority: P2)

Installing the package creates the service's system user and group, `/var/log/ispconfig-rest` and `/var/cache/ispconfig-rest` with the right owner and mode, and a boot-time declaration that recreates them. It writes no configuration and runs no migration. The application rotates its own log (daily, 14 days), so no logrotate policy is shipped.

**Why this priority**: These are fixed facts of the deployment today created by `install.sh`; Salt should not have to know them, and a package upgrade must restore them if they are missing.

**Independent Test**: Install the package in a clean container: the user and group exist and both directories exist with the declared owner and mode. Remove the directories and reinstall or run the tmpfiles step: they come back.

**Acceptance Scenarios**:

1. **Given** a clean host, **When** the package is installed, **Then** the user, group and directories exist and nothing under `/etc` is written by the package except the empty configuration directory.
2. **Given** the package is removed, **When** the host is inspected, **Then** `.env`, the logs and the database are untouched; purge removes the cache directory only; the system user and group stay, as Debian policy asks.
3. **Given** a directory deleted by hand, **When** the package is upgraded or the tmpfiles step runs, **Then** it is recreated with the right owner and mode.

---

### User Story 4 - Configuration and runtime data stay outside the packaged tree (Priority: P2)

The packaged tree is read-only at runtime. The environment file lives in a configuration directory (default `/etc/ispconfig-rest/`), which Salt owns, and every write the application makes (logs, framework caches, storage) goes to `/var`.

**Why this priority**: A package upgrade replaces its files; configuration inside the tree would be overwritten, and a tree under `/usr` must not be written to at runtime.

**Independent Test**: Run the application with the packaged tree mounted read-only and the environment file in the configuration directory: requests, cache and log writes succeed.

**Acceptance Scenarios**:

1. **Given** the environment file in the configuration directory, **When** the application starts, **Then** it reads it from there and not from the packaged tree.
2. **Given** a package upgrade, **When** it completes, **Then** the environment file is unchanged.

---

### User Story 5 - Releases are built and signed by the release workflow (Priority: P3)

A pushed tag triggers a workflow that builds the application once with production dependencies only, wraps it as an architecture-independent `.deb`, signs it and the repository metadata with `apt-cosign-sign`, publishes it, and records the checksum.

**Why this priority**: The first four stories can be exercised with a locally built package; automation makes the result repeatable and verifiable.

**Independent Test**: Push a test tag to a fork; the workflow produces the package and its Sigstore bundle, and `cosign verify-blob` against the workflow identity passes.

**Acceptance Scenarios**:

1. **Given** a matching tag, **When** the workflow runs, **Then** the package contains `VERSION`, `ISPCONFIG_COMPAT`, `bin/`, `api/` and the production `vendor/` tree, and no tests, specs, `.git` or development dependencies.
2. **Given** the published package, **When** it is verified, **Then** the Sigstore identity is the release workflow of this repository.

### Edge Cases

- A node still has the git-based install in `/opt/ispconfig-rest`: the package does not touch it; the migration path from the old install is part of the plan, not of the package.
- `ispconfig-rest update` on a package install: it must say the package manager does updates, not run git.
- First install has no environment file: the service is unconfigured; `doctor` and `/readyz` report it, nothing crashes at install.
- The host has several PHP versions: the package ships no PHP binary choice; the PHP used is configured by Salt.
- `dbispconfig` is on a different host or needs different admin credentials: the privileged login is given to the command by environment variables or options, never stored by the package.
- A migration fails midway on MySQL (DDL is not transactional): the command stops, reports which one and leaves the others for the next run.

## API Contract *(mandatory)*

No REST endpoints change. `GET /` reports the package version (the `VERSION` file); an optional follow-up adds the API version to `GET /api/v1/status`.

- **Spec file(s)**: none changed by this feature.
- **Command contract** (the new surface):

| Command | Purpose | Exit codes |
|---------|---------|------------|
| `ispconfig-rest migrate` | Apply pending API migrations with the privileged login | 0 applied or up to date, 1 error |
| `ispconfig-rest migrate --check` | Report whether migrations are pending, write nothing | 0 up to date, 2 pending, 1 error |

## ISPConfig Parity & Datalog Impact *(mandatory)*

- **Legacy reference**: not applicable; this is deployment tooling.
- **Tables written (via datalog only)**: none. The migration command creates only the API's own tables (`api_keys`, the Laravel `migrations` bookkeeping table) and never alters, drops or writes an ISPConfig table (constitution Principle II). It does not apply ISPConfig schema patches.
- **Intentional deviations from legacy**: none.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The release workflow MUST build one architecture-independent package per tag, from a tree with production dependencies only, and MUST fail when the tag differs from `VERSION`.
- **FR-002**: The package MUST install without git, composer or network access and MUST contain the application, its `vendor/` tree, `VERSION`, `ISPCONFIG_COMPAT`, `bin/` and `api/`.
- **FR-003**: Package files, the publication metadata and the package MUST be signed so that `apt-cosign` verifies them against the release workflow identity of this repository.
- **FR-004**: The package MUST create the service user and group, `/var/log/ispconfig-rest` and `/var/cache/ispconfig-rest` with their owner and mode, and a boot-time declaration that recreates them, and MUST NOT write the environment file, the web-server or php-fpm configuration, or run a migration.
- **FR-005**: The application MUST read its environment file from a configuration directory outside the packaged tree and MUST NOT write inside the packaged tree at runtime.
- **FR-006**: `ispconfig-rest migrate` MUST apply the API's pending migrations with a privileged database login supplied through environment variables or options, MUST be idempotent (a second run changes nothing and exits 0), and MUST never prompt.
- **FR-007**: `ispconfig-rest migrate --check` MUST write nothing and MUST exit 0 when nothing is pending, 2 when something is pending and 1 on any error.
- **FR-008**: The migration command MUST refuse, before any DDL, when `server.dbversion` is below `min_dbversion`, naming both levels, and MUST NOT alter or write any ISPConfig table.
- **FR-009**: The migration command MUST report each applied migration and MUST state when it created `api_keys` on a database that previously had none.
- **FR-010**: The migration command MUST NOT print a password and MUST serialise concurrent runs so the end state equals a single run.
- **FR-011**: On a package install, `ispconfig-rest update` MUST NOT run git and MUST tell the operator that the package manager does updates.
- **FR-012**: `GET /` MUST report the installed package's version.

### Key Entities

- **Release tag**: the calendar version (`YYYY.MM.DD`, `.N` for a second release the same day); equals `VERSION`.
- **Package**: the signed `.deb` built from a tag; ships files and the system-user and directory declarations.
- **Migration command**: `ispconfig-rest migrate [--check]`; the only thing that runs the API's migrations.
- **Runtime layout**: read-only application tree, configuration directory, `/var/log/ispconfig-rest`, `/var/cache/ispconfig-rest`.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On a host with PHP and no git or composer, installing the package and running `ispconfig-rest migrate` takes no step that needs the network beyond fetching the package.
- **SC-002**: Running `ispconfig-rest migrate` twice in a row leaves the database identical after the first run and exits 0 both times.
- **SC-003**: A Salt state can decide to migrate from `--check` alone (exit 2) without parsing text.
- **SC-004**: A package upgrade leaves the environment file, the logs and the database unchanged.
- **SC-005**: A tampered or unsigned package is refused by the verifying transport on a test host.
- **SC-006**: `GET /` on a node installed from tag `T` reports `T`.

## Assumptions

- Nodes run Debian or Ubuntu; the package is architecture-independent because the tree is pure PHP and is built with `config.platform.php` set to 8.3.0, which the suite already runs on 8.3, 8.4 and 8.5.
- Salt keeps owning the environment file, the php-fpm pool, the web vhost, service reloads and the call to `migrate` and `doctor`; the package never does.
- Single-server installs only; multi-server stays a TODO of spec 010.
- Spec 010's checks stay as they are: the compat check, `doctor`, `/readyz` and the installer's schema pre-flight.
- `install.sh` stays as the non-package path; its migration step is replaced by a call to the same command so both paths run one implementation.

### Decisions taken as defaults

These were open; the maintainer had not answered, so the stated defaults are used and can be changed before the slices merge.

- **Run user**: a dedicated, package-owned system user and group `ispconfig-rest`; the php-fpm pool Salt writes runs as it. `install.sh` keeps using the web-server user.
- **Repository hosting**: the `apt-cosign` model: a flat repository force-published to a dedicated `apt-repo` branch, served by `raw.githubusercontent.com`, every file signed by the release workflow's GitHub Actions identity.
- **PHP dependency**: no hard `Depends` on a PHP CLI. The migration, `doctor` and key commands run through the PHP CLI (`artisan`), which the php-fpm package does not provide, so the manager uses the PHP binary Salt configures and `doctor` reports a PHP that is too old.
