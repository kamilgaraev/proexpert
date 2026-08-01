<?php

declare(strict_types=1);

use App\BusinessModules\Addons\EstimateGeneration\Support\TrainingBenchmarkOnlineMigrationRuntime;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    private const TABLE = 'estimate_generation_sheet_analysis_operations';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, fn (Blueprint $table) => $this->defineAllColumns($table));
        } else {
            $this->addMissingColumns();
        }
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $runtime = new TrainingBenchmarkOnlineMigrationRuntime;
        $timeouts = $runtime->configureSessionTimeouts();
        try {
            $this->backfillSafeDefaults();
            $this->assertIdentityAndScopeBackfillable();
            $this->ensureIdentityAndScopeColumns($runtime);
            $this->ensureOperationIdentity($runtime);
            $this->ensureScopeUnique($runtime);
            $runtime->ensureConcurrentIndex('eg_sheet_analysis_claim_idx', 'CREATE INDEX CONCURRENTLY eg_sheet_analysis_claim_idx ON estimate_generation_sheet_analysis_operations (status, lease_expires_at)');
            $this->ensureSessionForeignKey($runtime);
            $runtime->ensureConstraint(self::TABLE, 'eg_sheet_analysis_status_ck', "CHECK (kind IN ('primary','targeted') AND status IN ('queued','claimed','completed','failed','needs_review','exhausted') AND ((lease_token IS NULL AND lease_expires_at IS NULL) OR (lease_token IS NOT NULL AND lease_expires_at IS NOT NULL)) AND jsonb_typeof(analysis_payload) = 'object' AND jsonb_typeof(initial_routing) = 'object' AND jsonb_typeof(final_routing) = 'object')");
            $runtime->validateConstraint(self::TABLE, 'eg_sheet_analysis_status_ck');
            $this->assertNoDuplicateAuditTransitions();
            $runtime->ensureConcurrentIndex('eg_sheet_analysis_audit_transition_uq', "CREATE UNIQUE INDEX CONCURRENTLY eg_sheet_analysis_audit_transition_uq ON estimate_generation_audit_events (session_id, event_type, (payload->>'operation_id'), (payload->>'attempt')) WHERE event_type = 'sheet_targeted_reanalysis_transition'");
            $runtime->checkpoint('000300.sheet_analysis_complete');
        } finally {
            $runtime->restoreSessionTimeouts($timeouts);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sheet_analysis_audit_transition_uq');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sheet_analysis_claim_idx');
            DB::statement('ALTER TABLE IF EXISTS estimate_generation_sheet_analysis_operations DROP CONSTRAINT IF EXISTS estimate_generation_sheet_analysis_operations_pkey');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_sheet_analysis_operation_identity_uq');
        }
        Schema::dropIfExists(self::TABLE);
    }

    private function defineAllColumns(Blueprint $table): void
    {
        $table->uuid('operation_id')->primary();
        $table->string('kind', 16);
        $table->unsignedBigInteger('organization_id');
        $table->unsignedBigInteger('project_id');
        $table->unsignedBigInteger('session_id');
        $table->unsignedBigInteger('document_id');
        $table->unsignedBigInteger('unit_id');
        $table->char('source_version', 71);
        $table->string('status', 16);
        $table->uuid('lease_token')->nullable();
        $table->timestampTz('lease_expires_at')->nullable();
        $table->unsignedInteger('attempt_count')->default(0);
        $table->jsonb('analysis_payload')->default('{}');
        $table->jsonb('initial_routing')->default('{}');
        $table->jsonb('final_routing')->default('{}');
        $table->string('failure_reason', 160)->nullable();
        $table->timestampTz('completed_at')->nullable();
        $table->timestampsTz();
    }

    private function addMissingColumns(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (! Schema::hasColumn(self::TABLE, 'operation_id')) { $table->uuid('operation_id'); }
            if (! Schema::hasColumn(self::TABLE, 'kind')) { $table->string('kind', 16)->default('primary'); }
            foreach (['organization_id', 'project_id', 'session_id', 'document_id', 'unit_id'] as $column) {
                if (! Schema::hasColumn(self::TABLE, $column)) { $table->unsignedBigInteger($column)->nullable(); }
            }
            if (! Schema::hasColumn(self::TABLE, 'source_version')) { $table->char('source_version', 71)->default(''); }
            if (! Schema::hasColumn(self::TABLE, 'status')) { $table->string('status', 16)->default('queued'); }
            if (! Schema::hasColumn(self::TABLE, 'lease_token')) { $table->uuid('lease_token')->nullable(); }
            if (! Schema::hasColumn(self::TABLE, 'lease_expires_at')) { $table->timestampTz('lease_expires_at')->nullable(); }
            if (! Schema::hasColumn(self::TABLE, 'attempt_count')) { $table->unsignedInteger('attempt_count')->default(0); }
            foreach (['analysis_payload', 'initial_routing', 'final_routing'] as $column) {
                if (! Schema::hasColumn(self::TABLE, $column)) { $table->jsonb($column)->default('{}'); }
            }
            if (! Schema::hasColumn(self::TABLE, 'failure_reason')) { $table->string('failure_reason', 160)->nullable(); }
            if (! Schema::hasColumn(self::TABLE, 'completed_at')) { $table->timestampTz('completed_at')->nullable(); }
            if (! Schema::hasColumn(self::TABLE, 'created_at')) { $table->timestampTz('created_at')->nullable(); }
            if (! Schema::hasColumn(self::TABLE, 'updated_at')) { $table->timestampTz('updated_at')->nullable(); }
        });
    }

    private function ensureScopeUnique(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
    {
        if (DB::selectOne('SELECT 1 FROM pg_constraint WHERE conname = ?', ['eg_sheet_analysis_scope_kind_uq']) !== null) {
            return;
        }
        $runtime->ensureConcurrentIndex('eg_sheet_analysis_scope_kind_idx', 'CREATE UNIQUE INDEX CONCURRENTLY eg_sheet_analysis_scope_kind_idx ON estimate_generation_sheet_analysis_operations (session_id, document_id, unit_id, source_version, kind)');
        DB::statement('ALTER TABLE estimate_generation_sheet_analysis_operations ADD CONSTRAINT eg_sheet_analysis_scope_kind_uq UNIQUE USING INDEX eg_sheet_analysis_scope_kind_idx');
    }

    private function backfillSafeDefaults(): void
    {
        DB::statement(<<<'SQL'
UPDATE estimate_generation_sheet_analysis_operations
SET kind = COALESCE(NULLIF(kind, ''), 'primary'),
    status = COALESCE(NULLIF(status, ''), 'queued'),
    attempt_count = COALESCE(attempt_count, 0),
    analysis_payload = COALESCE(analysis_payload, '{}'::jsonb),
    initial_routing = COALESCE(initial_routing, '{}'::jsonb),
    final_routing = COALESCE(final_routing, '{}'::jsonb),
    created_at = COALESCE(created_at, CURRENT_TIMESTAMP),
    updated_at = COALESCE(updated_at, CURRENT_TIMESTAMP)
SQL);
    }

    private function assertIdentityAndScopeBackfillable(): void
    {
        $invalid = DB::selectOne(<<<'SQL'
SELECT 1
FROM estimate_generation_sheet_analysis_operations
WHERE operation_id IS NULL
   OR operation_id::text !~* '^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
   OR organization_id IS NULL OR project_id IS NULL OR session_id IS NULL OR document_id IS NULL OR unit_id IS NULL
   OR source_version IS NULL OR btrim(source_version) = '' OR char_length(source_version) > 71
LIMIT 1
SQL);
        if ($invalid !== null) {
            throw new \RuntimeException('estimate_generation.sheet_analysis_identity_backfill_required');
        }
    }

    private function ensureIdentityAndScopeColumns(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
    {
        foreach (['operation_id', 'organization_id', 'project_id', 'session_id', 'document_id', 'unit_id', 'source_version'] as $column) {
            $runtime->ensureConstraint(self::TABLE, 'eg_sheet_analysis_'.$column.'_nn', sprintf('%s IS NOT NULL', $column));
            $runtime->validateConstraint(self::TABLE, 'eg_sheet_analysis_'.$column.'_nn');
            DB::statement(sprintf('ALTER TABLE %s ALTER COLUMN %s SET NOT NULL', self::TABLE, $column));
        }
        $types = DB::select(<<<'SQL'
SELECT column_name, data_type
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = 'estimate_generation_sheet_analysis_operations'
  AND column_name IN ('operation_id', 'organization_id', 'project_id', 'session_id', 'document_id', 'unit_id', 'source_version')
SQL);
        $actual = [];
        foreach ($types as $type) {
            $actual[(string) $type->column_name] = (string) $type->data_type;
        }
        $expected = ['operation_id' => 'uuid', 'organization_id' => 'bigint', 'project_id' => 'bigint', 'session_id' => 'bigint', 'document_id' => 'bigint', 'unit_id' => 'bigint', 'source_version' => 'character'];
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            throw new \RuntimeException('estimate_generation.sheet_analysis_identity_scope_type_invalid');
        }
    }

    private function ensureOperationIdentity(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
    {
        $primary = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(c.oid, true) AS definition
FROM pg_constraint c
JOIN pg_class t ON t.oid = c.conrelid
WHERE t.relname = 'estimate_generation_sheet_analysis_operations' AND c.contype = 'p'
SQL);
        if ($primary !== null && strtolower(trim((string) $primary->definition)) !== 'primary key (operation_id)') {
            throw new \RuntimeException('estimate_generation.sheet_analysis_primary_key_invalid');
        }
        $runtime->ensureConcurrentIndex('eg_sheet_analysis_operation_identity_uq', 'CREATE UNIQUE INDEX CONCURRENTLY eg_sheet_analysis_operation_identity_uq ON estimate_generation_sheet_analysis_operations (operation_id)');
        if ($primary === null) {
            DB::statement('ALTER TABLE estimate_generation_sheet_analysis_operations ADD CONSTRAINT estimate_generation_sheet_analysis_operations_pkey PRIMARY KEY USING INDEX eg_sheet_analysis_operation_identity_uq');
        }
    }

    private function ensureSessionForeignKey(TrainingBenchmarkOnlineMigrationRuntime $runtime): void
    {
        $runtime->ensureConstraint(self::TABLE, 'eg_sheet_analysis_session_fk', 'FOREIGN KEY (session_id, organization_id, project_id) REFERENCES estimate_generation_sessions (id, organization_id, project_id) ON DELETE CASCADE');
        $runtime->validateConstraint(self::TABLE, 'eg_sheet_analysis_session_fk');
    }

    private function assertNoDuplicateAuditTransitions(): void
    {
        $duplicate = DB::selectOne(<<<'SQL'
SELECT session_id, payload->>'operation_id' AS operation_id, payload->>'attempt' AS attempt
FROM estimate_generation_audit_events
WHERE event_type = 'sheet_targeted_reanalysis_transition'
GROUP BY session_id, payload->>'operation_id', payload->>'attempt'
HAVING COUNT(*) > 1
LIMIT 1
SQL);
        if ($duplicate !== null) {
            throw new \RuntimeException('estimate_generation.sheet_analysis_audit_transition_duplicates');
        }
    }
};
