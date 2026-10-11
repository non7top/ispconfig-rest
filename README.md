# ISPConfig REST API

A modern, contract-first REST API for [ISPConfig 3.3](https://www.ispconfig.org/), built on Laravel 12. It exposes ISPConfig's full administration surface — clients, DNS, mail, sites, servers, monitoring, and system configuration — as ~270 industry-standard REST endpoints, while remaining a first-class citizen of ISPConfig's own change-management system.

## How it works

The API connects directly to ISPConfig's `dbispconfig` MySQL database, but **never modifies ISPConfig tables directly**. Every write is journaled through ISPConfig's `sys_datalog` table in the exact byte format the legacy interface produces, so ISPConfig's server daemons pick up and apply changes precisely as if they came from the built-in panel. Behavioral parity with the legacy interface (validation rules, derived fields, side effects, cascades) is reverse-engineered from the ISPConfig source and enforced by tests.

The OpenAPI 3 contract in [`api/`](api/) is the source of truth — the PHP implements it, not the other way around. Explore it live at `/api/documentation` (Swagger UI).

## Requirements

- PHP ≥ 8.3
- Composer
- Network access to an ISPConfig 3.3 MySQL database (`dbispconfig`) — reverse-engineered against 3.3.0p1, validated live against 3.3.1p1. The supported schema level (`server.dbversion`) and release are declared in [`ISPCONFIG_COMPAT`](ISPCONFIG_COMPAT); the installer refuses a database below it, and while it is below, writes are refused (reads keep working). Run ISPConfig's updater to fix it.

## Install on an ISPConfig server (recommended)

Run the installer on your existing ISPConfig 3.3 host and it wires everything up:

```bash
curl -sSL https://raw.githubusercontent.com/FELDSAM-INC/ispconfig-rest/main/install.sh | sudo bash
```

What it does:

- **Reads the runtime database credentials** from ISPConfig's own config
  (`/usr/local/ispconfig/interface/lib/config.inc.php`).
- **Creates the API-owned `api_keys` table using a privileged (root) DB login** —
  by default MySQL root over the local unix socket, exactly as ISPConfig's own
  installer does — so the runtime user needs no `CREATE` right and nothing about
  ISPConfig is modified. (Pass `--db-admin-pass` if root needs a password.)
- **Detects your web server** (Apache or nginx) and creates a **dedicated
  php-fpm pool plus a dedicated vhost** on its own HTTPS port (default 8090) that
  **reuses the ISPConfig panel SSL certificate** (`ispserver.crt`/`.key`/`.bundle`) —
  the same php-fpm serving model ISPConfig uses for its own sites. The vhost is
  standalone — it never edits ISPConfig's own interface (8080) or apps (8081)
  vhosts, which ISPConfig regenerates on update. (If php-fpm isn't installed,
  Apache falls back to mod_php.)
- **Opens the port in ISPConfig's own firewall** — it adds the port to the
  server's `firewall` record through the datalog, so ISPConfig's firewall plugin
  (bastille/ufw) reconfigures natively. It never creates a firewall record where
  none exists (that would restrict the firewall to only this port); if ISPConfig
  isn't managing the firewall it falls back to an active ufw/firewalld.
- **Registers `ispconfig-rest`** in your PATH and offers to mint an admin key.

Every prompt has a flag and `ISPC_REST_*` env var for unattended installs — see
`sudo ./install.sh --help`. Reference templates live in [`deploy/`](deploy/):
[Apache vhost](deploy/apache-vhost.conf.example),
[nginx vhost](deploy/nginx-vhost.conf.example),
[php-fpm pool](deploy/php-fpm-pool.conf.example).

### Managing the installation

```bash
ispconfig-rest status                        # service state, version, DB connectivity
ispconfig-rest update                        # check the ISPConfig schema level, pull latest, install deps, migrate, restart
ispconfig-rest key:create "my integration"   # mint an admin key
ispconfig-rest key:create "acme" --client-id 42   # mint a client-scoped key
ispconfig-rest firewall:allow 8090           # open a port in the ISPConfig firewall
ispconfig-rest doctor                        # check the database, ISPConfig schema level, migrations and logs
ispconfig-rest restart | logs -f | version | uninstall
```

## Manual / development installation

```bash
git clone https://github.com/FELDSAM-INC/ispconfig-rest.git && cd ispconfig-rest
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your ISPConfig database credentials (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`), then create the API's own key table and mint a key:

```bash
php artisan migrate            # creates only the api_keys table — ISPConfig tables are never migrated
php artisan api:key:create "my integration"
```

Run the development server with `php artisan serve`.

## Authentication

Every request requires an API key in the `X-API-Key` header. Keys are stored SHA-256-hashed and bound to an ISPConfig `sys_userid`/`sys_groupid`, which all datalogged changes are attributed to.

```
X-API-Key: isp_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

For local development, setting `API_DEV_KEY` in `.env` enables a fixed key that authenticates as the ISPConfig admin (local/development/testing environments only).

### Permission scope

Each key is bound to an ISPConfig user, and access follows ISPConfig's own `sys_perm_*` model:

- A key for the **admin** user (`sys_userid` 1) has unrestricted access — the default when you mint a key without `--client-id`.
- A key bound to a **client or reseller** (`php artisan api:key:create "label" --client-id=N`) sees and mutates only the rows that user's AUTHSQL grants (own rows, rows in its groups, world-readable rows). Rows it cannot read return `404`; rows it can read but not modify return `403`. The admin-only modules — `servers`, `system`, `monitor`, and `resellers` — return `403` in full. On create, the key's identity is stamped onto the row; client-supplied `sys_userid`/`sys_groupid` values are ignored.

Scoped keys are also bound by their client's **resource limits** (`client.limit_*`): creating past a booked cap (e.g. `limit_maildomain`) returns `403`, and quota-sum limits (mailbox/web/database quota) are enforced on create and update. Resellers are additionally capped by their own limits. Admin keys are unaffected.

## Conventions

- **Lists**: `GET /api/v1/{module}/{resource}?limit=25&offset=0&sort=domain&order=asc` returns `{ "data": [...], "meta": { "total", "limit", "offset" } }`. Unknown query parameters are rejected with `400` — filters are never silently ignored.
- **Errors**: RFC 9457 `application/problem+json` — `{ "type", "title", "status", "detail" }`, plus an `errors` map on validation failures (`422`).
- **Problem types**: refusals an integration acts on carry a stable `type` URI instead of `about:blank` (spec 023, [docs/problems.md](docs/problems.md)): `…/docs/problems.md#account-locked`, `#limit-reached` and `#quota-exceeded` (403, with a `limit` member — `name`, `scope`, `max`, `used`, plus `unit` and `requested` for quotas), `#feature-not-allowed` (403, with `feature`, the client limit column) and `#validation-failed` (422), whose `error_types` map marks fields refused as `feature-not-allowed` or `server-not-assigned`. Status codes, titles and details are unchanged; compare the `type`, not the text.
- **Incompatible ISPConfig schema**: a missing table or column, or a schema level below `ISPCONFIG_COMPAT`, is a `500` problem of type `…/docs/problems.md#incompatible-ispconfig-schema` naming only the table and column, or the found and required levels (never SQL). Writes are refused before any row is created.
- **Status codes**: `200` read/update, `201` create, `204` delete, `400/401/404/409/422` as problem+json.
- **Probes and status**: `GET /livez` and `GET /readyz` (unauthenticated, per-check states only, `readyz` is `503` when the database, the schema level or the migrations fail; `/up` is the framework's own check) and, for administrator keys, `GET /api/v1/status` with the levels behind it.
- **Logs**: unhandled failures are logged to `/var/log/ispconfig-rest/` (rotated daily, 14 days), falling back to the PHP error log; a broken log never changes a response.
- **Let's Encrypt outcome**: `GET /api/v1/sites/web-domains/{id}/ssl/status` reports `none`, `requested`, `issued` or `failed` for a website's free certificate. ISPConfig switches Let's Encrypt back off without a journal entry when issuance fails, so the state is derived from the enabling change, its processing status and the current flags. Failure reasons (`domain_not_reachable`, `issuance_failed`, `certificate_not_found`, `client_unavailable`) need the server log level set to Warning or Debug (System → Server Config → Server → Log level); otherwise the reason is `unknown`. Certificate validity is returned only when the API can read `<document_root>/ssl/<domain>-le.crt` (single-server installations).
- **Async writes**: a successful write confirms the `sys_datalog` journal entry; ISPConfig's daemons apply it within their next cycle (typically ≤ 1 minute). Every write that journaled at least one entry returns an `X-Change-Set-Id` header; any key polls `GET /api/v1/changes/{id}` until the status is `applied`, `failed` or `stalled`, or lists its pending and failed changes with `GET /api/v1/changes?status=pending`. No-change updates, validation failures and writes to API-owned data (API keys) return no header. `/monitor/data-logs` stays the admin view of journal payloads.
- **Booleans**: ISPConfig's `y/n` enum columns are exposed as JSON booleans and stored in the column's native case.

## Modules

| Module | Resources |
|--------|-----------|
| `clients` | clients, resellers, client domains, templates, template assignments, circles |
| `dns` | zones (SOA), records (incl. SPF/DKIM/DMARC stored as TXT like legacy), slave zones, templates |
| `mail` | domains, mailboxes (+ autoresponder/cc/filters/password/spamfilter sub-resources), forwards, alias domains, fetchmail, transports, relay domains/recipients, access rules, content filters, spamfilter config/policies/users/wblist |
| `sites` | web domains (+ SSL and Let's Encrypt status sub-resources), child domains, FTP/shell users, databases, database users, cron jobs, web folders/folder users, WebDAV users |
| `servers` | servers, per-section server config, firewall, IP addresses, IP mappings, PHP versions |
| `system` | global config panels, directive snippets, DNS CAA policies, resync |
| `changes` | processing status of journaled writes: change sets, pending/failed list, record view (every key) |
| `monitor` | datalog journal, per-server status, system logs |

## Testing

```bash
php artisan test
```

The suite (560+ tests) runs against an in-memory sqlite database with ISPConfig-shaped schemas and asserts, among other things, the exact byte format of every `sys_datalog` payload. CI runs on every push (`.github/workflows/tests.yml`).

## Known deviations from legacy ISPConfig

Deliberate and documented in code where they occur:

- **Interface-session behaviors are not replicated**: `use_domain_module` first-enable domain seeding, `maintenance_mode` session purge, and `session_timeout` → `sys_config` sync are legacy UI-session concerns with no REST equivalent.
- **`resync_client` does not raise the interface plugin event** `client:client:on_after_update` (un-raisable outside the legacy interface); datalog re-emission is performed.
- **`server.config` has two write disciplines, both legacy-faithful**: the server-config endpoints datalog their updates (as `server_config_edit.php` does); the mail `spamfilter/config` endpoint writes without datalog (as the legacy spamfilter panel does).
- **Directive-snippet in-use checks use exact ID matching** — legacy's REGEXP substring-matches (snippet 5 matches "15"); a regression test documents the divergence.
- **Deleting a DNS zone with records returns `400`** instead of legacy's silent cascade (declared in the contract).
- **DNS CAA policy writes are datalogged** although legacy writes `dns_ssl_ca` with direct SQL (whose insert is broken upstream) — a documented superset.

## Project governance

Engineering rules live in [`.specify/memory/constitution.md`](.specify/memory/constitution.md); per-module specifications in [`specs/`](specs/). The legacy ISPConfig source used as the parity reference is expected (untracked) at `source_code/`.

## License

BSD-3-Clause — see the LICENSE file for details.
