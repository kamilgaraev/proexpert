#!/usr/bin/env bash

is_full_release_sha() {
    [[ ${1-} =~ ^[0-9a-f]{40}$ ]]
}

read_release_attestation() {
    local path=$1
    local -a lines

    [[ -f $path && ! -L $path ]] || return 1
    mapfile -t lines <"$path"
    ((${#lines[@]} == 1)) || return 1
    is_full_release_sha "${lines[0]}" || return 1
    printf '%s\n' "${lines[0]}"
}

verify_release_attestation() {
    local path=$1
    local reviewed_sha=$2
    local deployed_sha

    is_full_release_sha "$reviewed_sha" || return 1
    deployed_sha=$(read_release_attestation "$path") || return 1
    [[ $deployed_sha == "$reviewed_sha" ]]
}

verify_protected_release_attestation() {
    local path=$1
    local reviewed_sha=$2
    local expected_uid=${3:-0}
    local expected_gid=${4:-0}
    local directory
    local mode

    [[ $expected_uid =~ ^[0-9]+$ && $expected_gid =~ ^[0-9]+$ ]] || return 1
    directory=$(dirname "$path")
    [[ -d $directory && ! -L $directory ]] || return 1
    [[ $(stat -c '%u:%g' "$directory") == "$expected_uid:$expected_gid" ]] || return 1
    mode=$(stat -c '%a' "$directory")
    (((8#$mode & 0022) == 0)) || return 1

    [[ -f $path && ! -L $path ]] || return 1
    [[ $(stat -c '%u:%g' "$path") == "$expected_uid:$expected_gid" ]] || return 1
    mode=$(stat -c '%a' "$path")
    (((8#$mode & 0022) == 0)) || return 1

    verify_release_attestation "$path" "$reviewed_sha"
}
