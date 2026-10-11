# Data Model: Prebuilt Debian Package and Salt-callable Migrations

No database entity is added or changed. The entities are release artefacts and runtime paths.

## Release tag
`YYYY.MM.DD`, with `.N` for a second release on the same day. Equals the `VERSION` file at the tagged commit; the package version is the tag.

## Package `ispconfig-rest`
Architecture `all`. Contents: `/usr/share/ispconfig-rest/` (application, `vendor/`, `VERSION`, `ISPCONFIG_COMPAT`, `api/`, `bin/`), `/usr/bin/ispconfig-rest` (the manager), `/usr/lib/tmpfiles.d/ispconfig-rest.conf`. No `Depends` on PHP.

## Runtime layout
| Path | Owner / mode | Written by |
|------|--------------|------------|
| `/usr/share/ispconfig-rest` | root, read-only at runtime | package |
| `/etc/ispconfig-rest/` | root:ispconfig-rest 0750 | package creates it empty; Salt writes `.env` |
| `/var/log/ispconfig-rest/` | ispconfig-rest 0750 | the application |
| `/var/cache/ispconfig-rest/` | ispconfig-rest 0750 | the application (storage and bootstrap caches) |

## Migration command result
Exit code: 0 applied or up to date; 1 error; 2 pending (only with `--check`). Output lines are listed in [`contracts/migrate-command.md`](contracts/migrate-command.md).
