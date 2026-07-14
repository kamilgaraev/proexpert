#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
WORKFLOW="$ROOT/.github/workflows/deploy-backend.yml"
DOCKERFILE="$ROOT/Dockerfile.prod"
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-production-readiness.md"
ENV_EXAMPLE="$ROOT/.env.example"

grep -Fq '${GITHUB_SHA}' "$WORKFLOW"
grep -Fq 'permissions:' "$WORKFLOW"
grep -Fq 'packages: write' "$WORKFLOW"
grep -Fq 'steps.build.outputs.digest' "$WORKFLOW"
grep -Fq 'IMAGE_REF="${IMAGE_REPO}@${IMAGE_DIGEST}"' "$WORKFLOW"
grep -Fq 'git reset --hard "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'test "$(git rev-parse HEAD)" = "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'MOST_IMAGE_REF="${IMAGE_REF}" docker compose run --rm --no-deps api php artisan migrate:safe --force' "$WORKFLOW"
grep -Fq 'MOST_IMAGE_REF="${IMAGE_REF}" docker compose up -d --force-recreate --remove-orphans' "$WORKFLOW"
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

echo 'AI estimator production deploy contract passed'
