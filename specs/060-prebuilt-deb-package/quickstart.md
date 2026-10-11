# Quickstart: verifying spec 060

1. **Migrate command**: against a scratch MySQL database with the ISPConfig `server` table at level 101 or higher, run `ispconfig-rest migrate --check` (exit 2 when `api_keys` is missing), `ispconfig-rest migrate` (exit 0, prints `applied …` and the empty-table line), then both again (exit 0, `up to date`). With `server.dbversion` below 101 it exits 1 and changes nothing.
2. **Environment path**: put `.env` in `/etc/ispconfig-rest/` with the packaged tree mounted read-only and request `GET /readyz`.
3. **Package**: `scripts/build-deb.sh <version>` then `scripts/test-deb.sh` (container); check the user, the directories and their modes, and `GET /` reporting the version.
4. **Release**: push a test tag to a fork; the workflow produces the signed repository; `cosign verify-blob` on the `.deb` bundle against the workflow identity passes.
