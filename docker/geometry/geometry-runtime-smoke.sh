#!/bin/sh
set -eu

workspace="$(mktemp -d /tmp/most-geometry-runtime-smoke.XXXXXX)"
trap 'rm -rf -- "$workspace"' EXIT HUP INT TERM

if ! /usr/local/bin/geometry-sandbox \
    "$workspace" \
    "$workspace/process.stdout" \
    "$workspace/process.stderr" \
    10 \
    262144 \
    10 \
    4096 \
    64 \
    /opt/geometry-venv/bin/python \
    -c 'import subprocess; import pypdfium2; from PIL import Image; assert "0.13.4" in subprocess.check_output(["/opt/libredwg/bin/dwgread", "--version"], text=True); print("geometry-runtime-smoke")'; then
    cat "$workspace/process.stderr" >&2
    exit 1
fi

test "$(cat "$workspace/process.stdout")" = "geometry-runtime-smoke"
test ! -s "$workspace/process.stderr"
