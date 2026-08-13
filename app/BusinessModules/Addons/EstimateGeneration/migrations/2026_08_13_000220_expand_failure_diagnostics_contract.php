<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $previousLockTimeout = (string) DB::scalar('SHOW lock_timeout');
        $previousStatementTimeout = (string) DB::scalar('SHOW statement_timeout');
        DB::statement("SET lock_timeout TO '5s'");
        DB::statement("SET statement_timeout TO '120s'");

        try {
            foreach ([
                'eg_failure_events_safe_context_closed_ck_v2',
                'eg_failure_events_safe_context_values_ck_v2',
                'eg_failure_events_safe_context_privacy_ck_v2',
            ] as $constraint) {
                DB::statement(sprintf(
                    'ALTER TABLE public.estimate_generation_failure_events DROP CONSTRAINT IF EXISTS %s',
                    $constraint,
                ));
            }
            DB::statement('ALTER TABLE public.estimate_generation_failure_identities DROP CONSTRAINT IF EXISTS eg_failure_identities_provider_model_ck_v2');

            DB::statement(<<<'SQL'
            ALTER TABLE public.estimate_generation_failure_events
            ADD CONSTRAINT eg_failure_events_safe_context_closed_ck_v2 CHECK (
                jsonb_typeof(safe_context) = 'object'
                AND (safe_context - ARRAY[
                    'provider_code','provider','provider_error_type','provider_error_code','provider_error_param',
                    'endpoint_kind','body_fingerprint','body_shape_fingerprint','prompt_contract_fingerprint',
                    'payload_shape_fingerprint','http_class','http_code','provider_http_status','status','safe_code',
                    'retry_after_seconds','attempt','validation_code','storage_code','claim_status','lineage_code',
                    'failure_fingerprint','diagnostic_fingerprint','exception_chain_fingerprint','exception_class',
                    'root_exception_class','execution_boundary','processing_attempt_id','requested_model'
                ]) = '{}'::jsonb
            ) NOT VALID
        SQL);
            DB::unprepared(<<<'SQL'
            ALTER TABLE public.estimate_generation_failure_events
            ADD CONSTRAINT eg_failure_events_safe_context_values_ck_v2 CHECK (
                (NOT safe_context ? 'http_code' OR (jsonb_typeof(safe_context->'http_code') = 'number' AND (safe_context->>'http_code')::integer BETWEEN 100 AND 599))
                AND (NOT safe_context ? 'provider_http_status' OR (jsonb_typeof(safe_context->'provider_http_status') = 'number' AND (safe_context->>'provider_http_status')::integer BETWEEN 100 AND 599))
                AND (NOT safe_context ? 'retry_after_seconds' OR (jsonb_typeof(safe_context->'retry_after_seconds') = 'number' AND (safe_context->>'retry_after_seconds')::integer BETWEEN 0 AND 86400))
                AND (NOT safe_context ? 'attempt' OR (jsonb_typeof(safe_context->'attempt') = 'number' AND (safe_context->>'attempt')::integer BETWEEN 1 AND 1000))
                AND (NOT safe_context ? 'http_class' OR safe_context->>'http_class' ~ '^[1-5]xx$')
                AND (NOT safe_context ? 'claim_status' OR safe_context->>'claim_status' IN ('lost','expired','stale','busy'))
                AND (NOT safe_context ? 'processing_attempt_id' OR safe_context->>'processing_attempt_id' ~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
                AND (NOT safe_context ? 'requested_model' OR safe_context->>'requested_model' ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,79}(/[A-Za-z0-9][A-Za-z0-9._-]{0,79})?$')
                AND (NOT safe_context ? 'provider' OR safe_context->>'provider' ~ '^[a-z][a-z0-9_]{0,39}$')
                AND (NOT safe_context ? 'provider_code' OR safe_context->>'provider_code' ~ '^[a-z][a-z0-9._-]{0,79}$')
                AND (NOT safe_context ? 'provider_error_type' OR safe_context->>'provider_error_type' ~ '^[A-Za-z][A-Za-z0-9._-]{0,79}$')
                AND (NOT safe_context ? 'provider_error_code' OR safe_context->>'provider_error_code' ~ '^[A-Za-z][A-Za-z0-9._-]{0,79}$')
                AND (NOT safe_context ? 'provider_error_param' OR safe_context->>'provider_error_param' ~ '^[A-Za-z][A-Za-z0-9._\[\]-]{0,79}$')
                AND (NOT safe_context ? 'endpoint_kind' OR safe_context->>'endpoint_kind' ~ '^[a-z][a-z0-9_]{0,39}$')
                AND (NOT safe_context ? 'status' OR safe_context->>'status' ~ '^[a-z][a-z0-9_]{0,39}$')
                AND (NOT safe_context ? 'safe_code' OR safe_context->>'safe_code' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'validation_code' OR safe_context->>'validation_code' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'storage_code' OR safe_context->>'storage_code' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'lineage_code' OR safe_context->>'lineage_code' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'exception_class' OR safe_context->>'exception_class' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'root_exception_class' OR safe_context->>'root_exception_class' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'execution_boundary' OR safe_context->>'execution_boundary' ~ '^[a-z][a-z0-9_]{0,79}$')
                AND (NOT safe_context ? 'body_fingerprint' OR safe_context->>'body_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'body_shape_fingerprint' OR safe_context->>'body_shape_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'prompt_contract_fingerprint' OR safe_context->>'prompt_contract_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'payload_shape_fingerprint' OR safe_context->>'payload_shape_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'failure_fingerprint' OR safe_context->>'failure_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'diagnostic_fingerprint' OR safe_context->>'diagnostic_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
                AND (NOT safe_context ? 'exception_chain_fingerprint' OR safe_context->>'exception_chain_fingerprint' ~ '^sha256:[0-9a-f]{64}$')
            ) NOT VALID
        SQL);
            DB::statement(<<<'SQL'
            ALTER TABLE public.estimate_generation_failure_events
            ADD CONSTRAINT eg_failure_events_safe_context_privacy_ck_v2 CHECK (
                NOT jsonb_path_exists(
                    safe_context,
                    '$.* ? (@.type() == "string" && @ like_regex "(authorization|api_key|apikey|token|secret|password|cookie|bearer|eyj[a-z0-9_-]{8,}\\.|akia[0-9a-z]{12,}|gh[pousr]_[0-9a-z]{12,}|sk-[0-9a-z]{8,})" flag "i")'
                )
            ) NOT VALID
        SQL);
            DB::statement(<<<'SQL'
            ALTER TABLE public.estimate_generation_failure_identities
            ADD CONSTRAINT eg_failure_identities_provider_model_ck_v2 CHECK (
                (provider IS NULL OR provider ~ '^[a-z][a-z0-9_]{0,39}$')
                AND (model IS NULL OR model ~ '^[A-Za-z0-9][A-Za-z0-9._-]{0,79}(/[A-Za-z0-9][A-Za-z0-9._-]{0,79})?$')
                AND lower(COALESCE(provider, '') || COALESCE(model, '')) !~ '(token|secret|password|bearer|api[_-]?key|eyj|sk-|gh[pousr]_)'
            ) NOT VALID
        SQL);

            DB::statement('ALTER TABLE public.estimate_generation_failure_events VALIDATE CONSTRAINT eg_failure_events_safe_context_closed_ck_v2');
            DB::statement('ALTER TABLE public.estimate_generation_failure_events VALIDATE CONSTRAINT eg_failure_events_safe_context_values_ck_v2');
            DB::statement('ALTER TABLE public.estimate_generation_failure_events VALIDATE CONSTRAINT eg_failure_events_safe_context_privacy_ck_v2');
            DB::statement('ALTER TABLE public.estimate_generation_failure_identities VALIDATE CONSTRAINT eg_failure_identities_provider_model_ck_v2');

            DB::transaction(function (): void {
                DB::statement("SET LOCAL lock_timeout TO '5s'");
                DB::statement('LOCK TABLE public.estimate_generation_failure_events, public.estimate_generation_failure_identities IN ACCESS EXCLUSIVE MODE');
                DB::unprepared(<<<'SQL'
                ALTER TABLE public.estimate_generation_failure_events
                    DROP CONSTRAINT IF EXISTS eg_failure_events_safe_context_closed_ck,
                    DROP CONSTRAINT IF EXISTS eg_failure_events_safe_context_values_ck,
                    DROP CONSTRAINT IF EXISTS eg_failure_events_safe_context_privacy_ck;
                ALTER TABLE public.estimate_generation_failure_events
                    RENAME CONSTRAINT eg_failure_events_safe_context_closed_ck_v2 TO eg_failure_events_safe_context_closed_ck;
                ALTER TABLE public.estimate_generation_failure_events
                    RENAME CONSTRAINT eg_failure_events_safe_context_values_ck_v2 TO eg_failure_events_safe_context_values_ck;
                ALTER TABLE public.estimate_generation_failure_events
                    RENAME CONSTRAINT eg_failure_events_safe_context_privacy_ck_v2 TO eg_failure_events_safe_context_privacy_ck;
                ALTER TABLE public.estimate_generation_failure_identities
                    DROP CONSTRAINT IF EXISTS eg_failure_identities_provider_model_ck;
                ALTER TABLE public.estimate_generation_failure_identities
                    RENAME CONSTRAINT eg_failure_identities_provider_model_ck_v2 TO eg_failure_identities_provider_model_ck;
                SQL);
            });
        } finally {
            DB::statement("SELECT set_config('lock_timeout', ?, false)", [$previousLockTimeout]);
            DB::statement("SELECT set_config('statement_timeout', ?, false)", [$previousStatementTimeout]);
        }
    }

    public function down(): void
    {
        throw new LogicException('estimate_generation_failure_diagnostics_contract_is_irreversible');
    }
};
