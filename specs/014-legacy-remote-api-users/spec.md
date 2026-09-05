# Feature Specification: Legacy Remote-API Users

**Feature Branch**: `014-legacy-remote-api-users`
**Created**: 2026-09-05
**Status**: Implemented (abbreviated process - see Process note below)
**Module**: system
**Input**: External consumer request (wr-salt-new, a Salt-managed ISPConfig fleet): manage `remote_user.remote_ips` idempotently, without raw SQL, the same way this API already replaced raw-SQL server-config states with real endpoints.

## Why

ISPConfig ships TWO independent APIs:

1. This one - `App\Models\ApiKey`/`api_keys`, `X-API-Key` header.
2. The legacy remote/SOAP API (`interface/web/remote/index.php`, `remoting_lib`, ~140 `client_*`/`dns_*`/`mail_*`/`sites_*`/`openvz_*` functions) - gated by the `remote_user` table this feature wraps.

wr-salt-new hit a real bug in #2: a self-hosted admin tool (`hostingfs`) calling ISPConfig's OWN node through its public URL hairpins back in as the node's own public IP, which `remote_user.remote_ips` (an allowlist) rejects with a silent SOAP fault. Their fix is a direct, idempotent SQL `UPDATE ... WHERE NOT FIND_IN_SET(...)` against `remote_user` - deliberately NOT run through this REST API, because this API had no endpoint for `remote_user` at all. Confirmed by enumerating every `Api/V1` controller at the pinned tag (`v1.0.0-rc.3`): no `RemoteUser`/`ApiKey`-for-`remote_user` controller exists.

That gap is real and this feature closes it: `remote_user` gets the same CRUD shape every other resource in this app has, so a consumer that wants to manage it (append a hairpin IP, rotate a password, add a scoped function grant) goes through `sys_datalog` and this API's usual contract instead of a raw `UPDATE`.

**Not in scope**: nothing about how `wr-salt-new` itself calls this endpoint. It parked its raw-SQL fix (its own PR #85) rather than blocking on this landing, since hostingfs v2 is being rebuilt against THIS API's other resources anyway and may stop needing `remote_user` entirely once that ships.

## Datalog classification (Principle II)

Documented superset, same shape as `DnsSslCa`/spec 008: legacy's `remote/index.php::login()` reads `remote_user` directly with a plain `SELECT`, at request time - no server daemon consumes `sys_datalog` for this table. Routing writes through `BaseModel` journals them anyway, for auditability, which is strictly additive over legacy (nothing consumes or is confused by the extra datalog rows).

## Endpoints

`GET/POST /system/remote-users`, `GET/PUT/DELETE /system/remote-users/{id}` - full CRUD, admin-only (`scope.admin`, same as the rest of the system module). See `api/modules/system/remote-users.yaml` for the contract and `api/components/schemas/RemoteUser.yaml` for the resource shape.

Two fields are exposed as arrays rather than the legacy comma-separated strings, matching the `csv_array` presentation `SystemConfigService` already established for `sys_ini.web_php_options`:
- `remote_ips` - source-IP allowlist. **Replaced wholesale on PUT, not merged** - a caller appending one IP must GET first and PUT the full union (see `RemoteUserApiTest::test_update_replaces_remote_ips_wholesale`). This is the exact operation wr-salt-new's #85 needs.
- `remote_functions` - the `remoting_lib` function names this account may call.

`remote_password` is write-only (`writeOnly: true` in the schema, `$hidden` in the model) - hashed with PHP's own `password_hash($password, PASSWORD_DEFAULT)`, confirmed (by wr-salt-new, against a live ISPConfig 3.3.1 node's real row) to be bcrypt on this ISPConfig version. Omitted or empty on update keeps the stored hash.

## Verification caveat (Principle I / III)

`source_code/` is untracked and was **not present in the environment this was implemented in**, so the `remote_user` column set and the "no DB UNIQUE KEY on `remote_username`" assumption were **not verified against `source_code/install/sql/ispconfig3.sql`** the way every other spec in this repo is. Instead:

- The column set (`sys_userid`, `sys_groupid`, `sys_perm_user`, `sys_perm_group`, `sys_perm_other`, `remote_username`, `remote_password`, `remote_access`, `remote_ips`, `remote_functions`, PK `remote_userid`) is taken from wr-salt-new's `ispconfig3/server_roles.sls` (`ispconfig_remote_api_user`), itself checked by that project against two live ISPConfig 3.3.1 nodes' real `remote_user` rows.
- The public remote-API function list (no `remote_user_*` function exists anywhere in `remoting_lib`) was confirmed by downloading the actual upstream `remoting.inc.php` and listing every public function in it - not from memory.
- `remote_username` uniqueness (409 on conflict) is an invariant this API chooses to enforce (mirroring `DnsSslCa.ca_issue`), not a confirmed DB constraint.

**Action for whoever next has `source_code/` available**: diff the assumed column set and `remote_user`'s real DDL (and its indexes) against this spec and the model/schema/test-schema; correct any mismatch before treating this as fully verified.

## Process note

This spec was written to document an implementation that was done directly (model, cast, controller, form requests, routes, OpenAPI schema+module+index+top-level registration, sqlite test-schema addition, 15 feature tests) rather than through the full `/speckit-specify` -> `/speckit-plan` -> `/speckit-tasks` dialogue the numbered specs before it went through. No `plan.md`/`tasks.md` accompany this one. All Quality Gate items (constitution, "Before a feature is considered done") were still checked directly: `vendor/bin/pint` clean on every new/changed file, `php artisan test` green (717 passed, including the 15 new ones, no regressions), and every write path routes through `BaseModel::save()`/`delete()` (no direct `remote_user` writes anywhere in the new code).
