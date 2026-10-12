#!/usr/bin/env bash
#
# Installs a built ispconfig-rest .deb in a clean container and checks it (spec 060).
#
#   scripts/test-deb.sh <path to .deb> [image]       (default image: php:8.3-cli)
#
# Runs as root inside a throwaway container; the host is untouched.
set -euo pipefail

deb="$(cd "$(dirname "${1:?usage: scripts/test-deb.sh <path to .deb> [image]}")" && pwd)/$(basename "$1")"
image="${2:-php:8.3-cli}"
version="$(basename "$deb" | sed -n 's/^ispconfig-rest_\(.*\)_all\.deb$/\1/p')"
[ -n "$version" ] || { echo "not a ispconfig-rest_<version>_all.deb file: $deb" >&2; exit 1; }

docker run --rm -v "$deb":/pkg/ispconfig-rest.deb:ro -e VERSION="$version" "$image" bash -euo pipefail -c '
fail() { echo "FAIL: $*" >&2; exit 1; }
check() { local what="$1"; shift; "$@" >/dev/null 2>&1 && echo "ok: $what" || fail "$what"; }
mode() { [ "$(stat -c "%U:%G %a" "$1")" = "$2" ] || fail "$1 is $(stat -c "%U:%G %a" "$1"), expected $2"; echo "ok: $1 $2"; }

dpkg -i /pkg/ispconfig-rest.deb >/dev/null
check "user and group exist" sh -c "getent passwd ispconfig-rest && getent group ispconfig-rest"
mode /etc/ispconfig-rest root:ispconfig-rest\ 750
mode /var/log/ispconfig-rest ispconfig-rest:ispconfig-rest\ 750
mode /var/cache/ispconfig-rest ispconfig-rest:ispconfig-rest\ 750
mode /var/cache/ispconfig-rest/storage/framework/cache/data ispconfig-rest:ispconfig-rest\ 750
[ ! -e /etc/ispconfig-rest/.env ] || fail "the package wrote an .env"
[ ! -e /etc/ispconfig-rest/install.conf ] || fail "the package wrote install.conf"
for p in tests specs .git .github .specify vendor/phpunit; do [ ! -e /usr/share/ispconfig-rest/$p ] || fail "/usr/share/ispconfig-rest/$p is shipped"; done
check "vendor tree and VERSION shipped" test -f /usr/share/ispconfig-rest/vendor/autoload.php -a -f /usr/share/ispconfig-rest/ISPCONFIG_COMPAT
[ "$(cat /usr/share/ispconfig-rest/VERSION)" = "$VERSION" ] || fail "VERSION differs from the package version"
ispconfig-rest version | grep -q "$VERSION" || fail "ispconfig-rest version does not print $VERSION"
echo "ok: ispconfig-rest version"

# a second install and a deleted directory are repaired
rm -rf /var/cache/ispconfig-rest
dpkg -i /pkg/ispconfig-rest.deb >/dev/null
mode /var/cache/ispconfig-rest/bootstrap ispconfig-rest:ispconfig-rest\ 750

# the application boots as the service user from a read-only tree with the .env in /etc
sed -e "s|^APP_KEY=.*|APP_KEY=base64:$(head -c32 /dev/zero | base64)|" -e "s|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|" -e "s|^DB_DATABASE=.*|DB_DATABASE=:memory:|" \
  /usr/share/ispconfig-rest/env.example > /etc/ispconfig-rest/.env
chown root:ispconfig-rest /etc/ispconfig-rest/.env; chmod 0640 /etc/ispconfig-rest/.env
cat > /tmp/boot.php <<PHP
<?php
chdir("/usr/share/ispconfig-rest");
require "vendor/autoload.php";
\$app = require "bootstrap/app.php";
\$status = \$app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create("/livez"))->getStatusCode();
Illuminate\\Support\\Facades\\Log::error("deb-test");
echo \$status, " ", \$app->environmentPath(), "\n";
exit(\$status === 200 ? 0 : 1);
PHP
chmod 0644 /tmp/boot.php
out="$(runuser -u ispconfig-rest -- php /tmp/boot.php)" || fail "the application does not boot: $out"
[ "$out" = "200 /etc/ispconfig-rest" ] || fail "unexpected boot result: $out"
echo "ok: boots as ispconfig-rest from /etc/ispconfig-rest/.env ($out)"
[ -f /var/cache/ispconfig-rest/bootstrap/packages.php ] || fail "the package manifest was not written to /var/cache"
echo "ok: runtime writes went to /var/cache"
logfile="$(grep -rl deb-test /var/log/ispconfig-rest || true)"
[ -n "$logfile" ] || fail "the application did not write its log to /var/log/ispconfig-rest"
[ "$(stat -c %U "$logfile")" = "ispconfig-rest" ] || fail "$logfile is not owned by ispconfig-rest"
echo "ok: the log was written to $logfile as ispconfig-rest"
[ -z "$(find /usr/share/ispconfig-rest -newer /pkg/ispconfig-rest.deb -type f)" ] || fail "something was written into the tree"

# updates belong to the package manager
if ispconfig-rest update >/tmp/update.out 2>&1; then fail "update succeeded on a package install"; fi
grep -q "apt" /tmp/update.out || fail "update does not point to apt"
echo "ok: update refuses and points to apt"

# purge keeps the user, logs and configuration
dpkg -P ispconfig-rest >/dev/null
[ ! -e /var/cache/ispconfig-rest ] || fail "purge left the cache directory"
getent passwd ispconfig-rest >/dev/null || fail "purge removed the user"
[ -e /var/log/ispconfig-rest ] && [ -e /etc/ispconfig-rest/.env ] || fail "purge removed logs or configuration"
echo "ok: purge removes only the cache"
echo "ALL CHECKS PASSED"
'
