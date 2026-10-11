#!/usr/bin/env bash
#
# Builds the ispconfig-rest .deb from the committed tree (spec 060).
#
#   scripts/build-deb.sh <version> [output dir]      (default: dist)
#
# Runs composer and dpkg-deb in throwaway containers; needs only docker, git
# and tar on the host. The version must equal the VERSION file.
set -euo pipefail

version="${1:?usage: scripts/build-deb.sh <version> [output dir]}"
out="${2:-dist}"
root="$(git -C "$(dirname "$0")/.." rev-parse --show-toplevel)"
cd "$root"

[ "$version" = "$(tr -d '[:space:]' < VERSION)" ] || { echo "version $version differs from VERSION $(cat VERSION)" >&2; exit 1; }
case "$version" in *[!0-9.]*|'') echo "version must be digits and dots (YYYY.MM.DD[.N]): $version" >&2; exit 1;; esac

uid="$(id -u):$(id -g)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
tree="$work/pkg/usr/share/ispconfig-rest"
mkdir -p "$tree" "$work/pkg/DEBIAN" "$work/pkg/usr/bin" "$work/pkg/usr/lib/tmpfiles.d"

# Only what runs: no tests, specs, CI, spec-kit files or git metadata.
git archive HEAD app bootstrap config database public resources routes api artisan composer.json composer.lock VERSION ISPCONFIG_COMPAT bin | tar -x -C "$tree"

docker run --rm --user "$uid" -e COMPOSER_HOME=/tmp/composer -v "$tree":/app -v composer-cache:/tmp/composer -w /app composer:2 \
  composer install --no-dev --no-scripts --classmap-authoritative --no-interaction --no-progress

install -m 0755 "$tree/bin/ispconfig-rest" "$work/pkg/usr/bin/ispconfig-rest"
printf 'deb\n' > "$tree/PACKAGED"
install -m 0644 packaging/deb/ispconfig-rest.tmpfiles "$work/pkg/usr/lib/tmpfiles.d/ispconfig-rest.conf"
install -m 0644 packaging/deb/env.example "$tree/env.example"
install -m 0755 packaging/deb/postinst packaging/deb/postrm "$work/pkg/DEBIAN/"
size="$(du -sk "$work/pkg" | cut -f1)"
sed -e "s/@VERSION@/$version/" -e "s/@SIZE@/$size/" packaging/deb/control.in > "$work/pkg/DEBIAN/control"
find "$work/pkg" -type d -exec chmod 0755 {} +

mkdir -p "$out"
docker run --rm --user "$uid" -v "$work":/work -v "$(cd "$out" && pwd)":/out debian:stable-slim \
  dpkg-deb --root-owner-group --build /work/pkg "/out/ispconfig-rest_${version}_all.deb"

echo "built $out/ispconfig-rest_${version}_all.deb"
