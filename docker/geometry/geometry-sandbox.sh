#!/bin/sh
set -eu

workspace="$(realpath "$1")"
stdout_file="$workspace/$(basename "$2")"
stderr_file="$workspace/$(basename "$3")"
timeout_seconds="$4"
memory_kib="$5"
cpu_seconds="$6"
file_blocks="$7"
open_files="$8"
shift 8

case "$stdout_file:$stderr_file" in
    "$workspace"/*:"$workspace"/*) ;;
    *) exit 125 ;;
esac

ulimit -v "$memory_kib"
ulimit -t "$cpu_seconds"
ulimit -f "$file_blocks"
ulimit -n "$open_files"

exec timeout -s KILL "$timeout_seconds" \
    bwrap \
    --die-with-parent \
    --new-session \
    --unshare-all \
    --ro-bind / / \
    --bind "$workspace" "$workspace" \
    --chdir "$workspace" \
    --dev /dev \
    --proc /proc \
    -- "$@" >"$stdout_file" 2>"$stderr_file"
