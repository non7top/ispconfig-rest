# Contract: `ispconfig-rest migrate`

```
ispconfig-rest migrate            apply pending API migrations
ispconfig-rest migrate --check    report whether any are pending; write nothing
```

Root only. The privileged login comes from the environment, never from arguments and never stored:

| Variable | Default | Meaning |
|----------|---------|---------|
| `ISPC_REST_DB_ADMIN_USER` | `root` | database login with DDL rights on `dbispconfig` |
| `ISPC_REST_DB_ADMIN_PASS` | empty | its password; empty with `root` means unix-socket authentication as OS root |

## Exit codes

| Code | `migrate` | `migrate --check` |
|------|-----------|-------------------|
| 0 | applied, or already up to date | nothing pending |
| 1 | error (login, schema level, lock, a migration failed) | error |
| 2 | — | one or more pending |

## Output (stdout, one line each)

- `up to date` — nothing pending.
- `applied <migration>` — once per migration applied.
- `api_keys created empty: existing keys are gone, mint new ones with ispconfig-rest key:create` — when the table did not exist before the run.
- Errors on stderr, e.g. `ISPConfig schema level 98 is below the required 101 (ISPConfig 3.3.1p1); nothing was changed`, `cannot log in to the database as root@localhost`, `another migration is running`. A password never appears.

## Guarantees

- Idempotent: a second run changes nothing and prints `up to date`.
- Refuses below `min_dbversion` before any DDL.
- Never alters or writes an ISPConfig table.
- After a failure partway, a later run applies only what is left.
