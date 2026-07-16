#!/bin/sh
set -eu

workspace="$1"
shift 2

exec bwrap \
    --die-with-parent \
    --new-session \
    --unshare-all \
    --ro-bind / / \
    --bind "$workspace" "$workspace" \
    --chdir "$workspace" \
    --dev /dev \
    --proc /proc \
    -- "$@"
