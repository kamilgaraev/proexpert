#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-operations.md"
VERIFIER="$ROOT/scripts/verify-ai-estimator-release-attestations.sh"
LIBRARY="$ROOT/scripts/lib/release-attestation.sh"
SHA_A=0123456789abcdef0123456789abcdef01234567
SHA_B=89abcdef0123456789abcdef0123456789abcdef

bash -n "$VERIFIER"
bash -n "$LIBRARY"

source "$VERIFIER"

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

TEST_LIBRARY="$TMP/release-attestation.sh"
MANIFEST="$TMP/smoke-ready.manifest"
SENTINEL="$TMP/gstack-called"
CURRENT_UID=$(id -u)
CURRENT_GID=$(id -g)

cp "$LIBRARY" "$TEST_LIBRARY"
chmod 0700 "$TMP"
chmod 0400 "$TEST_LIBRARY"

write_manifest() {
    local schema=$1
    local generation=$2
    local backend_sha=$3
    local admin_sha=$4

    rm -f "$MANIFEST"
    printf 'schema=%s\ngeneration=%s\nbackend_sha=%s\nadmin_sha=%s\n' \
        "$schema" "$generation" "$backend_sha" "$admin_sha" >"$MANIFEST"
    chmod 0400 "$MANIFEST"
}

assert_exit() {
    local expected=$1
    shift
    local actual

    set +e
    "$@" >/dev/null 2>&1
    actual=$?
    set -e

    if [[ $actual -ne $expected ]]; then
        echo "expected exit $expected, got $actual: $*" >&2
        exit 1
    fi
}

verify_fixture() {
    release_verifier_main_for_paths \
        "$TEST_LIBRARY" "$MANIFEST" "$CURRENT_UID" "$CURRENT_GID" \
        "$SHA_A" "$SHA_B"
}

write_manifest most-active-release/v1 7 "$SHA_A" "$SHA_B"
assert_exit 78 env \
    PRODUCTION_LIBRARY="$TEST_LIBRARY" \
    PRODUCTION_MANIFEST="$MANIFEST" \
    bash "$VERIFIER" "$SHA_A" "$SHA_B"
rm "$MANIFEST"

assert_exit 78 release_verifier_main_for_paths \
    "$TMP/missing-library.sh" "$MANIFEST" "$CURRENT_UID" "$CURRENT_GID" "$SHA_A" "$SHA_B"

chmod 0666 "$TEST_LIBRARY"
assert_exit 78 verify_fixture
chmod 0400 "$TEST_LIBRARY"

assert_exit 78 verify_fixture

write_manifest most-active-release/v2 7 "$SHA_A" "$SHA_B"
assert_exit 78 verify_fixture

write_manifest most-active-release/v1 invalid "$SHA_A" "$SHA_B"
assert_exit 78 verify_fixture

write_manifest most-active-release/v1 7 INVALID "$SHA_B"
assert_exit 78 verify_fixture

write_manifest most-active-release/v1 7 "$SHA_A" "$SHA_A"
assert_exit 78 verify_fixture

rm "$MANIFEST"
printf 'schema=most-active-release/v1\ngeneration=7\nbackend_sha=%s\nadmin_sha=%s\nextra=value\n' \
    "$SHA_A" "$SHA_B" >"$MANIFEST"
chmod 0400 "$MANIFEST"
assert_exit 78 verify_fixture

write_manifest most-active-release/v1 7 "$SHA_A" "$SHA_B"
chmod 0666 "$MANIFEST"
assert_exit 78 verify_fixture
chmod 0400 "$MANIFEST"

assert_exit 78 release_verifier_main_for_paths \
    "$TEST_LIBRARY" "$MANIFEST" "$((CURRENT_UID + 1))" "$CURRENT_GID" "$SHA_A" "$SHA_B"

mv "$MANIFEST" "$TMP/real.manifest"
ln -s "$TMP/real.manifest" "$MANIFEST"
assert_exit 78 verify_fixture
rm "$MANIFEST"

write_manifest most-active-release/v1 7 "$SHA_A" "$SHA_B"
OUTPUT=$(verify_fixture)
[[ $OUTPUT == $'generation=7\nbackend='"$SHA_A"$'\nadmin='"$SHA_B" ]]

run_gate_then_gstack_probe() {
    verify_fixture || return $?
    : >"$SENTINEL"
}

rm "$MANIFEST"
assert_exit 78 run_gate_then_gstack_probe
[[ ! -e $SENTINEL ]]

if grep -Eq 'backend\.sha256|admin\.sha256|DEPLOYED_(BACKEND|ADMIN)_SHA' "$RUNBOOK"; then
    echo "runbook must use only the atomic pair manifest" >&2
    exit 1
fi

grep -Fq '/var/lib/most-active-release/smoke-ready.manifest' "$VERIFIER"
grep -Fq 'flock' "$RUNBOOK"
grep -Fq '${GSTACK_BROWSE:-$HOME/.codex/skills/gstack/browse/dist/browse}' "$RUNBOOK"

INVALIDATE_LINE=$(grep -n 'rm -f "\$MANIFEST" "\$STATE/\$COMPONENT.active"' "$RUNBOOK" | head -1 | cut -d: -f1)
ACTIVATE_LINE=$(grep -n '^activate_component_and_wait_for_public_readiness' "$RUNBOOK" | head -1 | cut -d: -f1)
PUBLISH_LINE=$(grep -n 'mv -f "\$PAIR_TMP" "\$MANIFEST"' "$RUNBOOK" | head -1 | cut -d: -f1)
[[ $INVALIDATE_LINE -lt $ACTIVATE_LINE && $ACTIVATE_LINE -lt $PUBLISH_LINE ]]

VERIFY_LINE=$(grep -n 'verify-ai-estimator-release-attestations' "$RUNBOOK" | tail -1 | cut -d: -f1)
GSTACK_LINE=$(grep -n '\$B goto' "$RUNBOOK" | head -1 | cut -d: -f1)
[[ $VERIFY_LINE -lt $GSTACK_LINE ]]

echo "AI estimator atomic release manifest checks passed"
