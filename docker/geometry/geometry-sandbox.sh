#!/bin/sh
set -eu

workspace="$(realpath "$1")"
stdout_name="$(basename "$2")"
stderr_name="$(basename "$3")"
stdout_file="$workspace/$stdout_name"
stderr_file="$workspace/$stderr_name"
timeout_seconds="$4"
memory_kib="$5"
cpu_seconds="$6"
file_blocks="$7"
open_files="$8"
shift 8

case "$stdout_name:$stderr_name" in
    .:*|..:*|*:.*|*:..|*/*:*|*:*/*) exit 125 ;;
esac
test "$stdout_name" != "$stderr_name" || exit 125
case "$timeout_seconds:$memory_kib:$cpu_seconds:$file_blocks:$open_files" in
    *[!0-9:]*|0:*|*:0:*|*:*:0:*|*:*:*:0:*|*:*:*:*:0) exit 125 ;;
esac
case "$stdout_file:$stderr_file" in
    "$workspace"/*:"$workspace"/*) ;;
    *) exit 125 ;;
esac

ulimit -v "$memory_kib"
ulimit -t "$cpu_seconds"
ulimit -f "$file_blocks"
ulimit -n "$open_files"

stdout_tmp="$(mktemp "$workspace/.geometry-stdout.XXXXXX")"
stderr_tmp="$(mktemp "$workspace/.geometry-stderr.XXXXXX")"
trap 'rm -f -- "$stdout_tmp" "$stderr_tmp"' EXIT HUP INT TERM
landlock_sandbox="${GEOMETRY_LANDLOCK_SANDBOX_BINARY:-/usr/local/bin/geometry-landlock-sandbox}"

set +e
timeout -s KILL "$timeout_seconds" \
    "$landlock_sandbox" \
    "$workspace" \
    /usr/local/share/geometry-network-deny.bpf \
    "$@" >"$stdout_tmp" 2>"$stderr_tmp"
status=$?
set -e

mv -f -- "$stdout_tmp" "$stdout_file"
mv -f -- "$stderr_tmp" "$stderr_file"
trap - EXIT HUP INT TERM
exit "$status"
