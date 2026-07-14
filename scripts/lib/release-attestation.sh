#!/usr/bin/env bash

is_full_release_sha() {
    [[ ${1-} =~ ^[0-9a-f]{40}$ ]]
}

is_release_generation() {
    [[ ${1-} =~ ^[1-9][0-9]*$ ]]
}

is_protected_release_path() {
    local path=$1
    local expected_uid=$2
    local expected_gid=$3
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
    (((8#$mode & 0022) == 0))
}

read_active_release_manifest() {
    local path=$1
    local -a lines
    local generation
    local backend_sha
    local admin_sha

    mapfile -t lines <"$path"
    ((${#lines[@]} == 4)) || return 1
    [[ ${lines[0]} == 'schema=most-active-release/v1' ]] || return 1
    [[ ${lines[1]} == generation=* ]] || return 1
    [[ ${lines[2]} == backend_sha=* ]] || return 1
    [[ ${lines[3]} == admin_sha=* ]] || return 1

    generation=${lines[1]#generation=}
    backend_sha=${lines[2]#backend_sha=}
    admin_sha=${lines[3]#admin_sha=}

    is_release_generation "$generation" || return 1
    is_full_release_sha "$backend_sha" || return 1
    is_full_release_sha "$admin_sha" || return 1

    printf 'generation=%s\nbackend=%s\nadmin=%s\n' "$generation" "$backend_sha" "$admin_sha"
}

verify_active_release_manifest() {
    local path=$1
    local reviewed_backend_sha=$2
    local reviewed_admin_sha=$3
    local expected_uid=$4
    local expected_gid=$5
    local manifest
    local generation
    local backend_sha
    local admin_sha

    is_full_release_sha "$reviewed_backend_sha" || return 1
    is_full_release_sha "$reviewed_admin_sha" || return 1
    is_protected_release_path "$path" "$expected_uid" "$expected_gid" || return 1
    manifest=$(read_active_release_manifest "$path") || return 1

    generation=$(printf '%s\n' "$manifest" | sed -n 's/^generation=//p')
    backend_sha=$(printf '%s\n' "$manifest" | sed -n 's/^backend=//p')
    admin_sha=$(printf '%s\n' "$manifest" | sed -n 's/^admin=//p')
    [[ $backend_sha == "$reviewed_backend_sha" ]] || return 1
    [[ $admin_sha == "$reviewed_admin_sha" ]] || return 1

    printf 'generation=%s\nbackend=%s\nadmin=%s\n' "$generation" "$backend_sha" "$admin_sha"
}
