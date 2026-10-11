# Contract: package and release

## Package
- Name `ispconfig-rest`, version = release tag, architecture `all`, no `Depends` on PHP.
- `postinst` (idempotent): creates the group and user `ispconfig-rest` if missing (`useradd --system`, home `/usr/share/ispconfig-rest`, shell `/usr/sbin/nologin`), the directories of the runtime layout with their owner and mode, and runs `systemd-tmpfiles --create` when available. It writes no file under `/etc` except creating the empty configuration directory, and runs no migration.
- `postrm purge`: removes `/var/cache/ispconfig-rest`; keeps the user, logs, `/etc/ispconfig-rest` and the database.
- The manager `ispconfig-rest`, on a package install (marker file `/usr/share/ispconfig-rest/PACKAGED`), uses the package defaults (`INSTALL_DIR`, `RUN_USER=ispconfig-rest`) unless `/etc/ispconfig-rest/install.conf` overrides them, and `update` prints that the package manager does updates.

## Release workflow (`.github/workflows/release.yml`)
- Trigger: push of a tag matching `[0-9]*`.
- Steps: check `VERSION` equals the tag; build; `scripts/test-deb.sh`; sign and publish the flat repository to the `apt-repo` branch with the `apt-cosign` actions; create a GitHub release with the `.deb` and its sha256.
- Client source: `deb [trusted=yes] sigstore+https://raw.githubusercontent.com/non7top/ispconfig-rest/refs/heads/apt-repo/ ./` with the `apt-cosign` policy for this repository.
