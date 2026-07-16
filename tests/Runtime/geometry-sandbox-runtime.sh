#!/bin/bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ROOT="$(mktemp -d /tmp/most-geometry-sandbox.XXXXXX)"
trap 'rm -rf "$ROOT"' EXIT

mkdir -p "$ROOT/work" "$ROOT/outside"
if ! command -v bwrap >/dev/null 2>&1; then
    BWRAP_BINARY="${GEOMETRY_TEST_BWRAP:-$HOME/.cache/most-geometry-sandbox/bwrap}"
    test -x "$BWRAP_BINARY" || { echo 'geometry sandbox prerequisite missing; run bootstrap-geometry-sandbox-runtime.sh' >&2; exit 2; }
    export PATH="$(dirname "$BWRAP_BINARY"):$PATH"
fi

BWRAP_VERSION="$(bwrap --version | awk '{print $2}')"
test "$(printf '%s\n' '0.6.1' "$BWRAP_VERSION" | sort -V | head -n 1)" = '0.6.1'
bwrap --ro-bind / / --proc /proc --dev /dev --unshare-all -- sh -c 'exit 0'

cp "$PROJECT_ROOT/docker/geometry/geometry-sandbox.sh" "$ROOT/sandbox"
chmod 0755 "$ROOT/sandbox"
cp "$PROJECT_ROOT/tests/Runtime/geometry-landlock-sandbox-bwrap-adapter.sh" "$ROOT/landlock-sandbox"
chmod 0755 "$ROOT/landlock-sandbox"
export GEOMETRY_LANDLOCK_SANDBOX_BINARY="$ROOT/landlock-sandbox"
SANDBOX="$ROOT/sandbox"
WORK="$ROOT/work"
OUTSIDE="$ROOT/outside"

run_sandbox() {
    local stdout_name="$1"
    local stderr_name="$2"
    local wall="$3"
    local memory="$4"
    local cpu="$5"
    local file_blocks="$6"
    local open_files="$7"
    shift 7
    set +e
    "$SANDBOX" "$WORK" "$WORK/$stdout_name" "$WORK/$stderr_name" "$wall" "$memory" "$cpu" "$file_blocks" "$open_files" "$@"
    RUN_STATUS=$?
    set -e
}

run_sandbox stdout stderr 10 262144 5 128 64 sh -c 'printf inside > inside.txt; printf ok'
test "$RUN_STATUS" -eq 0
test "$(cat "$WORK/inside.txt")" = inside
test "$(cat "$WORK/stdout")" = ok

printf original > "$OUTSIDE/existing"
run_sandbox stdout stderr 10 262144 5 128 64 sh -c 'printf hacked > "$1"; printf new > "$2"' sh "$OUTSIDE/existing" "$OUTSIDE/new"
test "$RUN_STATUS" -ne 0
test "$(cat "$OUTSIDE/existing")" = original
test ! -e "$OUTSIDE/new"

printf victim > "$OUTSIDE/victim"
ln -sf "$OUTSIDE/victim" "$WORK/stdout"
run_sandbox stdout stderr 10 262144 5 128 64 sh -c 'printf safe'
test "$RUN_STATUS" -eq 0
test "$(cat "$OUTSIDE/victim")" = victim
test ! -L "$WORK/stdout"
test "$(cat "$WORK/stdout")" = safe

run_sandbox stdout stderr 10 262144 5 8 64 python3 -c 'import os
while True: os.write(1, b"x" * 8192)'
test "$RUN_STATUS" -ne 0
test "$(stat -c %s "$WORK/stdout")" -le 4096

run_sandbox stdout stderr 10 262144 5 8 64 python3 -c 'import os
while True: os.write(2, b"x" * 8192)'
test "$RUN_STATUS" -ne 0
test "$(stat -c %s "$WORK/stderr")" -le 4096

rm -f "$WORK/payload"
run_sandbox stdout stderr 10 262144 5 8 64 python3 -c 'with open("payload", "wb") as stream:
    while True: stream.write(b"x" * 8192)'
test "$RUN_STATUS" -ne 0
test "$(stat -c %s "$WORK/payload")" -le 4096

start="$(date +%s)"
run_sandbox stdout stderr 10 262144 1 128 64 python3 -c 'while True: pass'
elapsed=$(( $(date +%s) - start ))
test "$RUN_STATUS" -ne 0
test "$elapsed" -lt 8

run_sandbox stdout stderr 10 65536 5 128 64 python3 -c 'import sys
try: bytearray(1024 * 1024 * 1024)
except MemoryError: sys.exit(42)'
test "$RUN_STATUS" -eq 42

run_sandbox stdout stderr 10 262144 5 128 32 python3 -c 'import errno, os, sys
files=[]
try:
    while True: files.append(open("/dev/null"))
except OSError as error:
    sys.exit(43 if error.errno == errno.EMFILE else 44)'
test "$RUN_STATUS" -eq 43

start="$(date +%s)"
run_sandbox stdout stderr 1 262144 5 128 64 sleep 10
elapsed=$(( $(date +%s) - start ))
test "$RUN_STATUS" -ne 0
test "$elapsed" -lt 5

run_sandbox stdout stdout 10 262144 5 128 64 true
test "$RUN_STATUS" -eq 125
run_sandbox stdout stderr 0 262144 5 128 64 true
test "$RUN_STATUS" -eq 125

printf 'geometry sandbox runtime: PASS\n'
