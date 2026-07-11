#!/bin/bash
set -euo pipefail

CACHE_ROOT="${GEOMETRY_SANDBOX_CACHE:-$HOME/.cache/most-geometry-sandbox}"
PACKAGE='bubblewrap_0.6.1-1ubuntu0.1_amd64.deb'
ROOT="$(mktemp -d /tmp/most-geometry-bootstrap.XXXXXX)"
trap 'rm -rf "$ROOT"' EXIT

mkdir -p "$CACHE_ROOT"
cd "$ROOT"
apt download bubblewrap=0.6.1-1ubuntu0.1 >/dev/null
echo "f75c835d6871d1b36370e12ee82940334b2a9f94efc7b959b5b236447e89743d  $PACKAGE" | sha256sum -c - >/dev/null
dpkg-deb -x "$PACKAGE" package
install -m 0755 package/usr/bin/bwrap "$CACHE_ROOT/bwrap"
"$CACHE_ROOT/bwrap" --version
