<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_generation_ai_usage', function (Blueprint $table): void {
            $table->uuid('attempt_id')->primary();
            $table->uuid('correlation_id');
            $table->char('immutable_fingerprint', 71);
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('session_id');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('page_id')->nullable();
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('stage', 40);
            $table->string('operation', 24);
            $table->unsignedInteger('attempt_ordinal');
            $table->string('provider', 80);
            $table->string('requested_model', 160);
            $table->string('reported_model', 160)->nullable();
            $table->string('usage_status', 16);
            $table->string('status', 24);
            $table->unsignedSmallInteger('http_code')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('image_count')->default(0);
            $table->string('image_detail', 16)->nullable();
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedBigInteger('duration_ms');
            $table->jsonb('price_snapshot')->default('{}');
            $table->decimal('cost_amount', 18, 8)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('pricing_status', 16);
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['organization_id', 'session_id', 'created_at'], 'eg_usage_org_session_date_idx');
            $table->index(['stage', 'status', 'created_at'], 'eg_usage_stage_status_date_idx');
            $table->index(['provider', 'requested_model', 'created_at'], 'eg_usage_provider_model_date_idx');
            $table->index(['document_id', 'page_id', 'unit_id'], 'eg_usage_document_page_unit_idx');
            $table->foreign(['session_id', 'organization_id', 'project_id'], 'eg_usage_session_scope_fk')
                ->references(['id', 'organization_id', 'project_id'])->on('estimate_generation_sessions')->cascadeOnDelete();
            $table->foreign(['document_id', 'organization_id', 'project_id', 'session_id'], 'eg_usage_document_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id'])->on('estimate_generation_documents')->cascadeOnDelete();
            $table->foreign(['page_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_usage_page_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_document_pages')->cascadeOnDelete();
            $table->foreign(['unit_id', 'organization_id', 'project_id', 'session_id', 'document_id'], 'eg_usage_unit_scope_fk')
                ->references(['id', 'organization_id', 'project_id', 'session_id', 'document_id'])->on('estimate_generation_processing_units')->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_ck CHECK (stage IN ('understand_documents','match_normatives'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_operation_ck CHECK (operation IN ('ocr','vision','rerank'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_stage_operation_ck CHECK ((stage = 'match_normatives') = (operation = 'rerank'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_status_ck CHECK (status IN ('succeeded','http_failed','connection_failed','malformed_response'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_status_http_ck CHECK ((status = 'http_failed' AND http_code IS NOT NULL AND NOT (http_code BETWEEN 200 AND 299)) OR (status = 'connection_failed' AND http_code IS NULL) OR (status = 'succeeded' AND (http_code IS NULL OR http_code BETWEEN 200 AND 299)) OR (status = 'malformed_response' AND (http_code IS NULL OR http_code BETWEEN 200 AND 299)))");
            DB::unprepared("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_pricing_ck CHECK ((pricing_status = 'available' AND usage_status = 'measured' AND cost_amount IS NOT NULL AND currency IS NOT NULL AND price_snapshot ?& ARRAY['input_per_million','cached_input_per_million','output_per_million','currency','source','version','effective_at']) OR (pricing_status = 'unavailable' AND cost_amount IS NULL AND currency IS NULL))");
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_http_ck CHECK (http_code IS NULL OR http_code BETWEEN 100 AND 599)');
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_cached_ck CHECK (cached_input_tokens <= input_tokens)');
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_image_detail_ck CHECK (image_detail IS NULL OR image_detail IN ('low','high','auto'))");
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_attempt_ck CHECK (attempt_ordinal >= 1)');
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_usage_status_ck CHECK (usage_status IN ('measured','unavailable'))");
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_image_ck CHECK ((image_count = 0 AND image_detail IS NULL) OR (image_count > 0 AND image_detail IS NOT NULL))');
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_unit_document_ck CHECK (unit_id IS NULL OR document_id IS NOT NULL)');
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_page_document_ck CHECK (page_id IS NULL OR document_id IS NOT NULL)');
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_currency_ck CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}$')");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_identifiers_ck CHECK (provider ~ '^[a-z0-9._-]{1,80}$' AND requested_model ~ '^[A-Za-z0-9._/-]{1,160}$' AND (reported_model IS NULL OR reported_model ~ '^[A-Za-z0-9._/-]{1,160}$'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_json_ck CHECK (jsonb_typeof(price_snapshot) = 'object')");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_snapshot_closed_ck CHECK ((price_snapshot - ARRAY['input_per_million','cached_input_per_million','output_per_million','reasoning_per_million','reasoning_mode','image_unit','page_unit','currency','source','version','effective_at']) = '{}'::jsonb AND octet_length(price_snapshot::text) <= 4096)");
            DB::unprepared("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_snapshot_safe_ck CHECK (NOT (price_snapshot ?| ARRAY['prompt','messages','request','response','error','filename','path','secret','token','content','api_key','authorization']))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_snapshot_dims_ck CHECK (price_snapshot = '{}'::jsonb OR (price_snapshot->>'source' IN ('config','provider','contract','fixture') AND price_snapshot->>'version' ~ '^[A-Za-z0-9._-]{1,80}$' AND price_snapshot->>'currency' ~ '^[A-Z]{3}$'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_fingerprint_ck CHECK (immutable_fingerprint ~ '^sha256:[0-9a-f]{64}$')");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_uuid_ck CHECK (attempt_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' AND correlation_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$' AND attempt_id <> '00000000-0000-0000-0000-000000000000'::uuid AND correlation_id <> '00000000-0000-0000-0000-000000000000'::uuid)");
            DB::statement('ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_nonnegative_ck CHECK (input_tokens >= 0 AND cached_input_tokens >= 0 AND output_tokens >= 0 AND reasoning_tokens >= 0 AND image_count >= 0 AND page_count >= 0 AND duration_ms >= 0 AND (cost_amount IS NULL OR cost_amount >= 0))');
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_measurement_ck CHECK (usage_status = 'measured' OR (input_tokens = 0 AND cached_input_tokens = 0 AND output_tokens = 0 AND reasoning_tokens = 0))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_snapshot_values_ck CHECK (price_snapshot = '{}'::jsonb OR ((price_snapshot->>'input_per_million') ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$' AND (price_snapshot->>'cached_input_per_million') ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$' AND (price_snapshot->>'output_per_million') ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$' AND COALESCE(price_snapshot->>'reasoning_mode','excluded_from_output') IN ('included_in_output','excluded_from_output') AND (price_snapshot->>'effective_at') ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}$'))");
            DB::unprepared("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_optional_rates_ck CHECK ((NOT price_snapshot ? 'reasoning_per_million' OR price_snapshot->>'reasoning_per_million' ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$') AND (NOT price_snapshot ? 'image_unit' OR price_snapshot->>'image_unit' ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$') AND (NOT price_snapshot ? 'page_unit' OR price_snapshot->>'page_unit' ~ '^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,8})?$'))");
            DB::statement("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_snapshot_currency_ck CHECK (pricing_status <> 'available' OR price_snapshot->>'currency' = currency)");
            DB::unprepared("ALTER TABLE estimate_generation_ai_usage ADD CONSTRAINT eg_usage_counter_tariffs_ck CHECK (pricing_status <> 'available' OR ((page_count = 0 OR price_snapshot ? 'page_unit') AND (image_count = 0 OR price_snapshot ? 'image_unit') AND (reasoning_tokens = 0 OR price_snapshot ? 'reasoning_per_million')))");
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION prevent_estimate_generation_ai_usage_mutation() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE' OR (TG_OP = 'DELETE' AND pg_trigger_depth() = 1) THEN
                        RAISE EXCEPTION 'estimate_generation_ai_usage is immutable';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER eg_usage_immutable_guard
                BEFORE UPDATE OR DELETE ON estimate_generation_ai_usage
                FOR EACH ROW EXECUTE FUNCTION prevent_estimate_generation_ai_usage_mutation();
                SQL);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS prevent_estimate_generation_ai_usage_mutation() CASCADE');
        }
        Schema::dropIfExists('estimate_generation_ai_usage');
    }
};
