#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
WORKFLOW="$ROOT/.github/workflows/deploy-backend.yml"
DOCKERFILE="$ROOT/Dockerfile.prod"
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-production-readiness.md"
ENV_EXAMPLE="$ROOT/.env.example"
FLAGS_HELPER="$ROOT/.github/scripts/estimator-runtime-flags.sh"
ATOMIC_ENV_HELPER="$ROOT/.github/scripts/atomic-env.sh"

grep -Fq '${GITHUB_SHA}' "$WORKFLOW"
grep -Fq 'permissions:' "$WORKFLOW"
grep -Fq 'packages: write' "$WORKFLOW"
grep -Fq 'steps.build.outputs.digest' "$WORKFLOW"
grep -Fq 'IMAGE_REF="${IMAGE_REPO}@${IMAGE_DIGEST}"' "$WORKFLOW"
grep -Fq 'git reset --hard "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'test "$(git rev-parse HEAD)" = "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'MOST_IMAGE_REF="${IMAGE_REF}" docker compose run --rm --no-deps api php artisan migrate:safe --force' "$WORKFLOW"
grep -Fq 'MOST_IMAGE_REF="${IMAGE_REF}" docker compose up -d --no-deps --force-recreate ${BACKEND_SERVICES}' "$WORKFLOW"
grep -Fq 'curl -fsS http://localhost:8000/up' "$WORKFLOW"

grep -Fq 'ARG MOST_RELEASE_SHA' "$DOCKERFILE"
grep -Fq '/release.json' "$DOCKERFILE"
if grep -Fq 'public/release.json' "$DOCKERFILE"; then
    echo 'release endpoint must not be bypassed by the static file server' >&2
    exit 1
fi
FINAL_STAGE_LINE=$(grep -n '^FROM php:' "$DOCKERFILE" | cut -d: -f1)
RELEASE_ARG_LINE=$(grep -n '^ARG MOST_RELEASE_SHA$' "$DOCKERFILE" | cut -d: -f1)
[[ $RELEASE_ARG_LINE -gt $FINAL_STAGE_LINE ]]
grep -Fq 'REDIS_ESTIMATE_GENERATION_BENCHMARK_RETRY_AFTER=' "$ENV_EXAMPLE"
grep -Fq 'org-*/estimate-generation/sessions/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/sessions/*/pipeline/attempts/*/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/sessions/*/vision/v1/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/benchmarks/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/benchmark-imports/' "$RUNBOOK"
grep -Fq 'If-None-Match: *' "$RUNBOOK"
grep -Fq 'versionId' "$RUNBOOK"
grep -Fq '000400' "$RUNBOOK"
grep -Fq '000450' "$RUNBOOK"
grep -Fq '000950' "$RUNBOOK"
grep -Fq '001125' "$RUNBOOK"
grep -Fq '001150' "$RUNBOOK"
grep -Fq '001200' "$RUNBOOK"
grep -Fq 'most-module' "$RUNBOOK"
grep -Fq 'estimate-generation' "$RUNBOOK"
grep -Fq '"Action": "*"' "$RUNBOOK"
grep -Fq '"AllowedOrigins"' "$RUNBOOK"
if grep -Fq '"Filter": {"Prefix": "org-"}' "$RUNBOOK"; then
    echo 'lifecycle must never expire every organization object' >&2
    exit 1
fi
if grep -Fq 's3:HeadObject' "$RUNBOOK"; then
    echo 'IAM must not contain the nonexistent s3:HeadObject action' >&2
    exit 1
fi

grep -Fq 'vars.ESTIMATE_GENERATION_UNIVERSAL_ENABLED' "$WORKFLOW"
grep -Fq 'vars.ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED' "$WORKFLOW"
grep -Fq 'vars.ESTIMATE_GENERATION_LIVE_QA_ENABLED' "$WORKFLOW"
grep -Fq 'vars.ESTIMATE_GENERATION_LIVE_QA_RUN_ID' "$WORKFLOW"
grep -Fq 'apply_estimator_runtime_flags' "$WORKFLOW"
grep -Fq 'assert_estimator_runtime_config api' "$WORKFLOW"
grep -Fq 'assert_estimator_runtime_config horizon' "$WORKFLOW"
grep -Fq 'MOST_EXPECTED_ESTIMATE_GENERATION_UNIVERSAL_ENABLED' "$WORKFLOW"
grep -Fq 'MOST_EXPECTED_ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED' "$WORKFLOW"
grep -Fq 'MOST_EXPECTED_ESTIMATE_GENERATION_LIVE_QA_ENABLED' "$WORKFLOW"
grep -Fq 'ESTIMATE_GENERATION_LIVE_QA_ENABLED=true' "$RUNBOOK"
if grep -Fq 'docs/runbooks/ai-estimator-production-readiness.md' "$WORKFLOW"; then
    echo 'documentation-only changes must not trigger a production release' >&2
    exit 1
fi

TEST_DIR=$(mktemp -d)
trap 'rm -rf -- "$TEST_DIR"' EXIT
ENV_FILE="$TEST_DIR/.env"
cat > "$ENV_FILE" <<'ENVVARS'
KEEP=value
ESTIMATE_GENERATION_UNIVERSAL_ENABLED=false
ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=false
ESTIMATE_GENERATION_LIVE_QA_ENABLED=false
ENVVARS
chmod 600 "$ENV_FILE"
source "$ATOMIC_ENV_HELPER"
source "$FLAGS_HELPER"
initialize_secure_env

export ESTIMATE_GENERATION_UNIVERSAL_ENABLED=true
export ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=false
export ESTIMATE_GENERATION_LIVE_QA_ENABLED=true
export ESTIMATE_GENERATION_LIVE_QA_RUN_ID=123e4567-e89b-12d3-a456-426614174000
validate_estimator_runtime_flags
apply_estimator_runtime_flags
grep -Fxq 'KEEP=value' "$ENV_FILE"
grep -Fxq 'ESTIMATE_GENERATION_UNIVERSAL_ENABLED=true' "$ENV_FILE"
grep -Fxq 'ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=false' "$ENV_FILE"
grep -Fxq 'ESTIMATE_GENERATION_LIVE_QA_ENABLED=true' "$ENV_FILE"
grep -Fxq 'ESTIMATE_GENERATION_LIVE_QA_RUN_ID=123e4567-e89b-12d3-a456-426614174000' "$ENV_FILE"

cp "$ENV_FILE" "$TEST_DIR/before-missing"
unset ESTIMATE_GENERATION_UNIVERSAL_ENABLED ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED
unset ESTIMATE_GENERATION_LIVE_QA_ENABLED ESTIMATE_GENERATION_LIVE_QA_RUN_ID
validate_estimator_runtime_flags
[[ "$ESTIMATOR_RUNTIME_EXPECTED_UNIVERSAL_ENABLED" = true ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_SHARED_CREDITS_ENABLED" = false ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_ENABLED" = true ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_PRESENT" = 1 ]]
apply_estimator_runtime_flags
cmp -s "$TEST_DIR/before-missing" "$ENV_FILE"

cat > "$TEST_DIR/dotenv-values.env" <<'ENVVARS'
KEEP=value
ESTIMATE_GENERATION_UNIVERSAL_ENABLED=0
ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED="1"
ESTIMATE_GENERATION_LIVE_QA_ENABLED='false'
ESTIMATE_GENERATION_LIVE_QA_RUN_ID=123e4567-e89b-12d3-a456-426614174000
ENVVARS
chmod 600 "$TEST_DIR/dotenv-values.env"
ENV_FILE="$TEST_DIR/dotenv-values.env"
initialize_secure_env
validate_estimator_runtime_flags
[[ "$ESTIMATOR_RUNTIME_EXPECTED_UNIVERSAL_ENABLED" = false ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_SHARED_CREDITS_ENABLED" = true ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_ENABLED" = false ]]
[[ "$ESTIMATOR_RUNTIME_EXPECTED_LIVE_QA_RUN_ID_PRESENT" = 1 ]]

cp "$ENV_FILE" "$TEST_DIR/before-invalid"
export ESTIMATE_GENERATION_UNIVERSAL_ENABLED=TRUE
if validate_estimator_runtime_flags; then
    echo 'uppercase boolean input must be rejected' >&2
    exit 1
fi
cmp -s "$TEST_DIR/before-invalid" "$ENV_FILE"

export ESTIMATE_GENERATION_UNIVERSAL_ENABLED=false
export ESTIMATE_GENERATION_LIVE_QA_ENABLED=false
export ESTIMATE_GENERATION_LIVE_QA_RUN_ID=not-a-uuid
if validate_estimator_runtime_flags; then
    echo 'malformed run ID must be rejected even when QA is disabled' >&2
    exit 1
fi
cmp -s "$TEST_DIR/before-invalid" "$ENV_FILE"

cat > "$TEST_DIR/no-run-id.env" <<'ENVVARS'
KEEP=value
ESTIMATE_GENERATION_LIVE_QA_ENABLED=false
ENVVARS
ENV_FILE="$TEST_DIR/no-run-id.env"
initialize_secure_env
export ESTIMATE_GENERATION_UNIVERSAL_ENABLED=true
export ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=false
export ESTIMATE_GENERATION_LIVE_QA_ENABLED=true
export ESTIMATE_GENERATION_LIVE_QA_RUN_ID=''
cp "$ENV_FILE" "$TEST_DIR/before-missing-uuid"
if validate_estimator_runtime_flags; then
    echo 'QA without a preserved or supplied UUID must be rejected' >&2
    exit 1
fi
cmp -s "$TEST_DIR/before-missing-uuid" "$ENV_FILE"

cat > "$TEST_DIR/stage-failure.env" <<'ENVVARS'
KEEP=value
ESTIMATE_GENERATION_UNIVERSAL_ENABLED=false
ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=false
ESTIMATE_GENERATION_LIVE_QA_ENABLED=false
ENVVARS
chmod 600 "$TEST_DIR/stage-failure.env"
ENV_FILE="$TEST_DIR/stage-failure.env"
initialize_secure_env
export ESTIMATE_GENERATION_UNIVERSAL_ENABLED=true
export ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED=true
export ESTIMATE_GENERATION_LIVE_QA_ENABLED=''
export ESTIMATE_GENERATION_LIVE_QA_RUN_ID=''
cp "$ENV_FILE" "$TEST_DIR/before-stage-failure"
real_awk() { command awk "$@"; }
awk() {
    if [[ "${ENV_FILE}" == *.estimator.* ]] && [[ "$*" == *'key=ESTIMATE_GENERATION_SHARED_CREDITS_ENABLED'* ]]; then
        return 1
    fi
    real_awk "$@"
}
if apply_estimator_runtime_flags; then
    echo 'injected staged awk failure must abort the flag update' >&2
    exit 1
fi
ENV_FILE="$TEST_DIR/stage-failure.env"
cmp -s "$TEST_DIR/before-stage-failure" "$ENV_FILE"
unset -f awk real_awk
cleanup_deployment_temporary_files
cleanup_estimator_runtime_flags

chmod 644 "$ENV_FILE"
if assert_env_security; then
    echo 'insecure environment file mode must be rejected in conditional context' >&2
    exit 1
fi
chmod 600 "$ENV_FILE"
assert_env_security

echo 'AI estimator production deploy contract passed (8 runtime cases)'
