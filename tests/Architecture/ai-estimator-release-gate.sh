#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-operations.md"
VERIFIER="$ROOT/scripts/verify-ai-estimator-release-attestations.sh"
LIBRARY="$ROOT/scripts/lib/release-attestation.sh"

test -f "$VERIFIER"
test -f "$LIBRARY"

bash -n "$VERIFIER"
bash -n "$LIBRARY"

if grep -Eq 'DEPLOYED_(BACKEND|ADMIN)_SHA' "$RUNBOOK"; then
    echo "runbook must not trust operator-provided deployed SHA values" >&2
    exit 1
fi

grep -Fq '${GSTACK_BROWSE:-$HOME/.codex/skills/gstack/browse/dist/browse}' "$RUNBOOK"
grep -Fq '/var/lib/most-release-attestations/backend.sha256' "$VERIFIER"
grep -Fq '/var/lib/most-release-attestations/admin.sha256' "$VERIFIER"

source "$LIBRARY"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

BACKEND="$TMP/backend.sha256"
ADMIN="$TMP/admin.sha256"
SHA_A=0123456789abcdef0123456789abcdef01234567
SHA_B=89abcdef0123456789abcdef0123456789abcdef

expect_failure() {
    if "$@" >/dev/null 2>&1; then
        echo "expected failure: $*" >&2
        exit 1
    fi
}

expect_failure verify_release_attestation "$BACKEND" "$SHA_A"

printf '%s\n' invalid >"$BACKEND"
expect_failure verify_release_attestation "$BACKEND" "$SHA_A"

printf '%s\n%s\n' "$SHA_A" "$SHA_A" >"$BACKEND"
expect_failure verify_release_attestation "$BACKEND" "$SHA_A"

printf '%s\n' "$SHA_B" >"$BACKEND"
expect_failure verify_release_attestation "$BACKEND" "$SHA_A"

printf '%s\n' "$SHA_A" >"$BACKEND"
verify_release_attestation "$BACKEND" "$SHA_A"

printf '%s\n' "$SHA_A" >"$ADMIN"
verify_release_attestation "$ADMIN" "$SHA_A"

CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)
chmod 0700 "$TMP"
chmod 0400 "$BACKEND"
verify_protected_release_attestation "$BACKEND" "$SHA_A" "$CURRENT_UID" "$CURRENT_GID"

chmod 0666 "$BACKEND"
expect_failure verify_protected_release_attestation "$BACKEND" "$SHA_A" "$CURRENT_UID" "$CURRENT_GID"

rm "$BACKEND"
ln -s "$ADMIN" "$BACKEND"
expect_failure verify_protected_release_attestation "$BACKEND" "$SHA_A" "$CURRENT_UID" "$CURRENT_GID"

echo "AI estimator release gate static checks passed"
