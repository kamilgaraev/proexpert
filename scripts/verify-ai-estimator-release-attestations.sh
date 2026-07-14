#!/usr/bin/env bash

set -euo pipefail

readonly PRODUCTION_LIBRARY=/usr/local/lib/most/release-attestation.sh
readonly PRODUCTION_MANIFEST=/var/lib/most-active-release/smoke-ready.manifest
readonly BLOCKED_EXIT=78

release_verifier_main_for_paths() {
    local library=$1
    local manifest=$2
    local expected_uid=$3
    local expected_gid=$4
    shift 4
    local library_directory
    local directory_mode
    local library_mode
    local verified

    if (($# != 2)); then
        echo "usage: verify <reviewed-backend-full-sha> <reviewed-admin-full-sha>" >&2
        return 64
    fi

    library_directory=$(dirname "$library")
    if [[ ! -d $library_directory || -L $library_directory || ! -f $library || -L $library ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library is absent or unsafe" >&2
        return "$BLOCKED_EXIT"
    fi

    if [[ $(stat -c '%u:%g' "$library_directory") != "$expected_uid:$expected_gid" || \
        $(stat -c '%u:%g' "$library") != "$expected_uid:$expected_gid" ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library has an unsafe owner" >&2
        return "$BLOCKED_EXIT"
    fi

    directory_mode=$(stat -c '%a' "$library_directory")
    library_mode=$(stat -c '%a' "$library")
    if (((8#$directory_mode & 0022) != 0 || (8#$library_mode & 0022) != 0)); then
        echo "BLOCKED_BY_DEPLOYMENT: trusted verifier library is writable by an untrusted user" >&2
        return "$BLOCKED_EXIT"
    fi

    source "$library"

    if ! verified=$(verify_active_release_manifest "$manifest" "$1" "$2" "$expected_uid" "$expected_gid"); then
        echo "BLOCKED_BY_DEPLOYMENT: active release manifest is absent, unsafe, invalid or does not match" >&2
        return "$BLOCKED_EXIT"
    fi

    printf '%s\n' "$verified"
}

production_main() {
    release_verifier_main_for_paths "$PRODUCTION_LIBRARY" "$PRODUCTION_MANIFEST" 0 0 "$@"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    production_main "$@"
fi
