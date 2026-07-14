#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
WORKFLOW="$ROOT/.github/workflows/deploy-backend.yml"
DOCKERFILE="$ROOT/Dockerfile.prod"
COORDINATOR="$ROOT/scripts/coordinate-most-release.sh"
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-production-readiness.md"
ENV_EXAMPLE="$ROOT/.env.example"

grep -Fq '${GITHUB_SHA}' "$WORKFLOW"
grep -Fq 'MOST_IMAGE_TAG=${RELEASE_SHA}' "$WORKFLOW"
grep -Fq 'docker compose run --rm --no-deps api php artisan migrate --force' "$WORKFLOW"

MIGRATE_LINE=$(grep -n 'docker compose run --rm --no-deps api php artisan migrate --force' "$WORKFLOW" | cut -d: -f1)
ACTIVATE_LINE=$(grep -n 'coordinate-most-release backend' "$WORKFLOW" | cut -d: -f1)
[[ $MIGRATE_LINE -lt $ACTIVATE_LINE ]]

if grep -Eq 'migrate:(safe|rollback|reset)|artisan migrate:rollback' "$WORKFLOW"; then
    echo 'deploy must keep database fix-forward' >&2
    exit 1
fi
if grep -Eq 'sed .*SENTRY_RELEASE|printf .*SENTRY_RELEASE' "$WORKFLOW"; then
    echo 'release metadata must change atomically with runtime activation' >&2
    exit 1
fi

grep -Fq 'ARG MOST_RELEASE_SHA' "$DOCKERFILE"
grep -Fq '/release.json' "$DOCKERFILE"
if grep -Fq 'public/release.json' "$DOCKERFILE"; then
    echo 'release endpoint must not be bypassed by the static file server' >&2
    exit 1
fi
FINAL_STAGE_LINE=$(grep -n '^FROM php:' "$DOCKERFILE" | cut -d: -f1)
RELEASE_ARG_LINE=$(grep -n '^ARG MOST_RELEASE_SHA$' "$DOCKERFILE" | cut -d: -f1)
[[ $RELEASE_ARG_LINE -gt $FINAL_STAGE_LINE ]]
grep -Fq 'coordinate-most-release.sh backend' "$WORKFLOW"
grep -Fq "'scripts/**'" "$WORKFLOW"
grep -Fq 'flock -x' "$COORDINATOR"
grep -Fq 'smoke-ready.manifest' "$COORDINATOR"
grep -Fq 'Cache-Control: no-store' "$COORDINATOR"
grep -Fq 'verify_public_release "$BACKEND_RELEASE_URL" "$backend"' "$COORDINATOR"
grep -Fq 'verify_public_release "$ADMIN_RELEASE_URL" "$admin"' "$COORDINATOR"
grep -Fq 'chown -R root:root "$staging"' "$COORDINATOR"
grep -Fq 'mv "$staging" "$release"' "$COORDINATOR"

grep -Fq 'REDIS_ESTIMATE_GENERATION_BENCHMARK_RETRY_AFTER=' "$ENV_EXAMPLE"
grep -Fq 'org-*/estimate-generation/sessions/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/sessions/*/vision/v1/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/benchmarks/' "$RUNBOOK"
grep -Fq 'org-*/estimate-generation/benchmark-imports/' "$RUNBOOK"
grep -Fq 'If-None-Match: *' "$RUNBOOK"
grep -Fq 'versionId' "$RUNBOOK"
grep -Fq '000400' "$RUNBOOK"
grep -Fq '000450' "$RUNBOOK"

echo 'AI estimator production deploy contract passed'
