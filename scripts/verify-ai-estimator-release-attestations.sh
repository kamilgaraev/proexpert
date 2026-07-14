#!/usr/bin/env bash

set -euo pipefail

readonly LIBRARY=/usr/local/lib/most/release-attestation.sh
readonly BACKEND_ATTESTATION=/var/lib/most-release-attestations/backend.sha256
readonly ADMIN_ATTESTATION=/var/lib/most-release-attestations/admin.sha256
readonly BLOCKED_EXIT=78

if (($# != 2)); then
    echo "usage: $0 <reviewed-backend-full-sha> <reviewed-admin-full-sha>" >&2
    exit 64
fi

library_directory=$(dirname "$LIBRARY")
if [[ ! -d $library_directory || -L $library_directory || ! -f $LIBRARY || -L $LIBRARY ]]; then
    echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library is absent or unsafe" >&2
    exit "$BLOCKED_EXIT"
fi

if [[ $(stat -c '%u:%g' "$library_directory") != 0:0 || $(stat -c '%u:%g' "$LIBRARY") != 0:0 ]]; then
    echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library has an unsafe owner" >&2
    exit "$BLOCKED_EXIT"
fi

directory_mode=$(stat -c '%a' "$library_directory")
library_mode=$(stat -c '%a' "$LIBRARY")
if (((8#$directory_mode & 0022) != 0 || (8#$library_mode & 0022) != 0)); then
    echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library is writable by an untrusted user" >&2
    exit "$BLOCKED_EXIT"
fi

source "$LIBRARY"

readonly REVIEWED_BACKEND_SHA=$1
readonly REVIEWED_ADMIN_SHA=$2

if ! verify_protected_release_attestation "$BACKEND_ATTESTATION" "$REVIEWED_BACKEND_SHA" 0 0; then
    echo "BLOCKED_BY_DEPLOYMENT: backend release attestation is absent, unsafe or does not match" >&2
    exit "$BLOCKED_EXIT"
fi

if ! verify_protected_release_attestation "$ADMIN_ATTESTATION" "$REVIEWED_ADMIN_SHA" 0 0; then
    echo "BLOCKED_BY_DEPLOYMENT: admin release attestation is absent, unsafe or does not match" >&2
    exit "$BLOCKED_EXIT"
fi

printf 'backend=%s\nadmin=%s\n' "$REVIEWED_BACKEND_SHA" "$REVIEWED_ADMIN_SHA"
