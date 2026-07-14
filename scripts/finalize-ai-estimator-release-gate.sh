#!/usr/bin/env bash

set -euo pipefail

readonly FINALIZE_BLOCKED_EXIT=78

finalize_stable_release_gate() {
    if (($# != 5)); then
        echo "usage: finalize <before> <after> <reviewed-backend-sha> <reviewed-admin-sha> <pass-marker>" >&2
        return 64
    fi

    local before=$1
    local after=$2
    local reviewed_backend=$3
    local reviewed_admin=$4
    local pass_marker=$5
    local marker_directory
    local marker_tmp
    local -a lines
    local generation
    local backend
    local admin

    rm -f -- "$pass_marker" || return "$FINALIZE_BLOCKED_EXIT"

    if [[ ! $reviewed_backend =~ ^[0-9a-f]{40}$ || ! $reviewed_admin =~ ^[0-9a-f]{40}$ ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: reviewed release pair is invalid" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    if [[ ! -f $before || -L $before || ! -f $after || -L $after ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: release attestation evidence is absent or unsafe" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    if ! cmp -s -- "$before" "$after"; then
        echo "BLOCKED_BY_DEPLOYMENT: active release changed during browser smoke" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    mapfile -t lines <"$before"
    if ((${#lines[@]} != 3)); then
        echo "BLOCKED_BY_DEPLOYMENT: normalized release attestation has an invalid schema" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    [[ ${lines[0]} == generation=* ]] || return "$FINALIZE_BLOCKED_EXIT"
    [[ ${lines[1]} == backend=* ]] || return "$FINALIZE_BLOCKED_EXIT"
    [[ ${lines[2]} == admin=* ]] || return "$FINALIZE_BLOCKED_EXIT"
    generation=${lines[0]#generation=}
    backend=${lines[1]#backend=}
    admin=${lines[2]#admin=}

    if [[ ! $generation =~ ^[1-9][0-9]*$ || ! $backend =~ ^[0-9a-f]{40}$ || ! $admin =~ ^[0-9a-f]{40}$ ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: normalized release attestation contains invalid values" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    if [[ $backend != "$reviewed_backend" || $admin != "$reviewed_admin" ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: normalized release attestation does not match the reviewed pair" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    if ! cmp -s -- "$before" <(printf 'generation=%s\nbackend=%s\nadmin=%s\n' "$generation" "$backend" "$admin"); then
        echo "BLOCKED_BY_DEPLOYMENT: release attestation is not byte-normalized" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    marker_directory=$(dirname "$pass_marker")
    if [[ ! -d $marker_directory || -L $marker_directory ]]; then
        echo "BLOCKED_BY_DEPLOYMENT: PASS marker directory is absent or unsafe" >&2
        return "$FINALIZE_BLOCKED_EXIT"
    fi

    marker_tmp=$(mktemp "$marker_directory/.ai-estimator-pass.XXXXXX") || return "$FINALIZE_BLOCKED_EXIT"
    if ! printf 'status=PASS\ngeneration=%s\nreviewed_backend=%s\nreviewed_admin=%s\n' \
        "$generation" "$reviewed_backend" "$reviewed_admin" >"$marker_tmp"; then
        rm -f -- "$marker_tmp"
        return "$FINALIZE_BLOCKED_EXIT"
    fi
    chmod 0644 "$marker_tmp" || {
        rm -f -- "$marker_tmp"
        return "$FINALIZE_BLOCKED_EXIT"
    }
    mv -f -- "$marker_tmp" "$pass_marker" || {
        rm -f -- "$marker_tmp"
        return "$FINALIZE_BLOCKED_EXIT"
    }
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    finalize_stable_release_gate "$@"
fi
