#!/usr/bin/env bash

set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
WORKFLOW="$ROOT/.github/workflows/deploy-backend.yml"
DOCKERFILE="$ROOT/Dockerfile.prod"
COORDINATOR="$ROOT/scripts/coordinate-most-release.sh"
RUNBOOK="$ROOT/docs/runbooks/ai-estimator-production-readiness.md"
ENV_EXAMPLE="$ROOT/.env.example"
BOOTSTRAP="$ROOT/scripts/install-most-release-coordinator.sh"

grep -Fq '${GITHUB_SHA}' "$WORKFLOW"
grep -Fq 'permissions:' "$WORKFLOW"
grep -Fq 'packages: write' "$WORKFLOW"
grep -Fq 'steps.build.outputs.digest' "$WORKFLOW"
grep -Fq 'IMAGE_REF="${IMAGE_REPO}@${IMAGE_DIGEST}"' "$WORKFLOW"
grep -Fq 'git checkout --detach "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'test "$(git rev-parse HEAD)" = "${RELEASE_SHA}"' "$WORKFLOW"
grep -Fq 'bash tests/Architecture/ai-estimator-production-deploy.sh' "$WORKFLOW"
grep -Fq 'sudo /usr/local/libexec/most/coordinate-most-release backend' "$WORKFLOW"
grep -Fq 'RELEASE_COORDINATOR_SHA256' "$WORKFLOW"
grep -Fq 'sha256sum /usr/local/libexec/most/coordinate-most-release' "$WORKFLOW"
grep -Fq 'most-release-coordinator/v2' "$WORKFLOW"
grep -Fq 'bash tests/Architecture/most-release-coordinator-behavior.sh' "$WORKFLOW"

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
grep -Fq "'scripts/**'" "$WORKFLOW"
grep -Fq 'flock -x' "$COORDINATOR"
grep -Fq 'smoke-ready.manifest' "$COORDINATOR"
grep -Fq 'Cache-Control:.*no-store' "$COORDINATOR"
grep -Fq 'verify_public_release "$BACKEND_RELEASE_URL" "$backend"' "$COORDINATOR"
grep -Fq 'verify_public_release "$ADMIN_RELEASE_URL" "$admin"' "$COORDINATOR"
grep -Fq 'chown -R root:root "$candidate"' "$COORDINATOR"
grep -Fq 'mv "$candidate" "$release"' "$COORDINATOR"
grep -Fq 'dc run --rm --no-deps api php artisan migrate --force' "$COORDINATOR"
grep -Fq 'RepoDigests' "$COORDINATOR"
grep -Fq 'health_gate' "$COORDINATOR"
grep -Fq 'previous_ref=' "$COORDINATOR"
grep -Fq 'is_digest_ref "$previous_ref"' "$COORDINATOR"
grep -Fq 'rollback_backend' "$COORDINATOR"
grep -Fq 'git -C "$root" show "$sha:docker-compose.yml"' "$COORDINATOR"
grep -Fq 'docker compose --project-directory' "$COORDINATOR"
grep -Fq 'find "$candidate" ! -type f ! -type d' "$COORDINATOR"
grep -Fq 'validate_admin_archive "$sealed/admin-release.tar.gz"' "$COORDINATOR"
grep -Fq 'realpath --canonicalize-existing' "$COORDINATOR"
grep -Fq 'sha256sum' "$COORDINATOR"
grep -Fq 'admin-release-quarantine' "$COORDINATOR"
grep -Fq 'most-release-coordinator/v2' "$COORDINATOR"
grep -Fq 'bootstrap-backend' "$COORDINATOR"
grep -Fq 'previous_compose' "$COORDINATOR"
grep -Fq 'visudo -cf' "$BOOTSTRAP"
grep -Fq 'expected_sha256' "$BOOTSTRAP"
grep -Fq 'backend [0-9a-f]* ghcr.io/* sha256:*' "$BOOTSTRAP"
grep -Fq 'admin [0-9a-f]* [0-9a-f]* [0-9a-f]*' "$BOOTSTRAP"
grep -Fq 'chown root:root "$BACKEND_ROOT/.env"' "$BOOTSTRAP"

grep -Fq 'REDIS_ESTIMATE_GENERATION_BENCHMARK_RETRY_AFTER=' "$ENV_EXAMPLE"
grep -Fq 'org-*/estimate-generation/sessions/' "$RUNBOOK"
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

bash "$ROOT/tests/Architecture/most-release-coordinator-behavior.sh"

echo 'AI estimator production deploy contract passed'
