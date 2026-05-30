# AI Assistant Knowledge Search Production Runbook

## Purpose

This runbook covers production rollout and operation of the AI assistant knowledge search index for all SaaS organizations.

## Prerequisites

- PostgreSQL has `pgvector` installed and enabled in the application database as extension `vector`.
- `AI_RAG_ENABLED=true` is set only after the extension and migrations are ready.
- `OPENAI_API_KEY` is configured for embeddings when `AI_RAG_EMBEDDING_PROVIDER=openai`.
- Horizon is running with the `supervisor-ai-rag` supervisor.

## Deploy Order

1. Enable `pgvector` on the database server or in the managed database UI.
2. Deploy backend code.
3. Run production migrations through the existing safe deployment pipeline.
4. Refresh Laravel caches through the deployment pipeline.
5. Confirm Horizon has a consumer for queue `ai-rag`.
6. Enable `AI_RAG_ENABLED=true` if it was intentionally held back.

The RAG migration does not try to create extensions with the application database user. If `vector` is missing, migration fails before table creation with a clear preflight error.

## SaaS Backfill

Initial backfill for all active organizations:

```bash
php artisan ai-assistant:rag-backfill --all --limit=50
```

The command queues one `ai-rag` job per active organization and records an `ai_rag_index_runs` row for each run.

Use a smaller limit for a cautious rollout:

```bash
php artisan ai-assistant:rag-backfill --all --limit=10
```

Use synchronous mode only for local diagnostics or a single organization:

```bash
php artisan ai-assistant:rag-backfill <organization_id> --sync
```

Bulk synchronous mode is guarded and must not be used in normal production operation.

## Scheduled Operation

Laravel scheduler queues incremental SaaS indexing hourly:

```bash
ai-assistant:rag-backfill --all --stale --stale-after-hours=${AI_RAG_STALE_AFTER_HOURS:-24} --limit=${AI_RAG_SCHEDULED_LIMIT:-50}
```

Output is appended to `storage/logs/schedule-ai-rag-backfill.log`. Failed schedule runs are logged to stderr.

## Verification

Check readiness from the admin UI:

- Open admin panel.
- Go to `AI-ассистент` -> `Чат`.
- Review the `База знаний ассистента` panel.
- Use `Обновить базу` to queue reindex for the current organization.

Backend checks:

```bash
php artisan ai-assistant:rag-backfill <organization_id> --sync
```

Database checks:

```sql
SELECT count(*) FROM ai_rag_sources;
SELECT count(*) FROM ai_rag_chunks WHERE embedding IS NOT NULL;
SELECT status, count(*) FROM ai_rag_index_runs GROUP BY status;
```

Queue checks:

- Horizon dashboard shows `supervisor-ai-rag`.
- Queue wait for `redis:ai-rag` stays below the alert threshold.
- Failed `IndexRagSourceJob` records `status=failed` and `last_error` in `ai_rag_index_runs`.

## Rollback

To pause retrieval without dropping data:

```env
AI_RAG_ENABLED=false
```

To pause new indexing:

- Pause `supervisor-ai-rag` in Horizon.
- Set `AI_RAG_SCHEDULED_LIMIT=1` or remove the schedule in the next deploy if a longer pause is required.

Do not drop `ai_rag_sources`, `ai_rag_chunks`, or `ai_rag_index_runs` during application rollback unless a database rollback has been explicitly planned.
