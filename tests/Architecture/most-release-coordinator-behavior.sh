#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
COORDINATOR="$ROOT/scripts/coordinate-most-release.sh"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

[[ $("$COORDINATOR" --version) == 'most-release-coordinator/v2' ]]

STATE="$TMP/state"
CONFIG="$TMP/coordinator.conf"
mkdir -p "$STATE"
source "$COORDINATOR"
ORIGINAL_PUBLISH_PAIR=$(declare -f publish_pair)
declare -F validate_admin_archive >/dev/null

chown() { :; }
install() { :; }

candidate="$TMP/candidate"
mkdir -p "$candidate/nested"
printf 'ok' >"$candidate/nested/file.txt"
validate_release_tree "$candidate"
ln -s /etc/passwd "$candidate/link"
if validate_release_tree "$candidate"; then
    echo 'symlink release entry was accepted' >&2
    exit 1
fi
rm "$candidate/link"
mkfifo "$candidate/fifo"
if validate_release_tree "$candidate"; then
    echo 'special release entry was accepted' >&2
    exit 1
fi

archive_source="$TMP/archive-source"
mkdir -p "$archive_source"
printf 'ok' >"$archive_source/file.txt"
ln -s /etc/passwd "$archive_source/link"
tar -C "$archive_source" -czf "$TMP/symlink.tar.gz" .
if validate_admin_archive "$TMP/symlink.tar.gz"; then
    echo 'symlink archive entry was accepted before extraction' >&2
    exit 1
fi
rm "$archive_source/link"
mkfifo "$archive_source/fifo"
tar -C "$archive_source" -czf "$TMP/fifo.tar.gz" .
if validate_admin_archive "$TMP/fifo.tar.gz"; then
    echo 'special archive entry was accepted before extraction' >&2
    exit 1
fi

admin_root="$TMP/admin"
failed_release="$admin_root/releases/$(printf 'a%.0s' {1..40})"
active_release="$admin_root/releases/$(printf 'b%.0s' {1..40})"
mkdir -p "$failed_release" "$active_release" "$admin_root/admin-release-quarantine"
quarantine_failed_admin_release "$admin_root" "$failed_release" "$active_release" 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-12-3'
[[ ! -e $failed_release ]]
[[ -d "$admin_root/admin-release-quarantine/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-12-3" ]]

previous_sha=$(printf 'c%.0s' {1..40})
previous_ref="ghcr.io/example/most@sha256:$(printf 'd%.0s' {1..64})"
previous_compose="$STATE/backend-compose/$previous_sha.yml"
mkdir -p "$(dirname "$previous_compose")"
printf 'services: {}\n' >"$previous_compose"
recorded_compose=
write_backend_env() { :; }
verify_sealed_compose() { [[ -f $1 ]]; }
dc() { recorded_compose=$BACKEND_COMPOSE_FILE; }
health_gate() { :; }
verify_runtime_images() { :; }
atomic_sha() { :; }
publish_pair() { :; }
rollback_backend "$TMP/backend" "$previous_ref" "$previous_sha" 'api' "$previous_compose"
[[ $recorded_compose == "$previous_compose" ]]

unset -f publish_pair
eval "$ORIGINAL_PUBLISH_PAIR"
BACKEND_RELEASE_URL=https://backend.invalid/release.json
ADMIN_RELEASE_URL=https://admin.invalid/release.json
printf '0\n' >"$STATE/generation.counter"
backend_sha=$(printf 'e%.0s' {1..40})
admin_sha=$(printf 'f%.0s' {1..40})
printf '%s\n' "$backend_sha" >"$STATE/backend.active"
verify_public_release() { return "${FAIL_VERIFY:-0}"; }
publish_pair
[[ ! -e $STATE/smoke-ready.manifest ]]
printf '%s\n' "$admin_sha" >"$STATE/admin.active"
publish_pair
grep -Fq "backend_sha=$backend_sha" "$STATE/smoke-ready.manifest"
grep -Fq "admin_sha=$admin_sha" "$STATE/smoke-ready.manifest"
FAIL_VERIFY=1
if publish_pair; then
    echo 'manifest publication accepted failed verification' >&2
    exit 1
fi
[[ ! -e $STATE/smoke-ready.manifest ]]

echo 'MOST release coordinator behavior passed'
