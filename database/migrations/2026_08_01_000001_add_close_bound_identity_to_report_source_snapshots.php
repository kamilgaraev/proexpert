<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const READY_IDENTITY_UNIQUE = 'report_source_snapshots_ready_source_identity_unique';

    public function up(): void
    {
        Schema::table('report_source_snapshots', function (Blueprint $table): void {
            $table->char('scope_identity_hash', 64)->nullable();
            $table->text('source_version')->nullable();
        });

        DB::statement(
            'ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_scope_identity_hash_check '
            ."CHECK (scope_identity_hash IS NULL OR scope_identity_hash ~ '^[a-f0-9]{64}$')",
        );
        DB::statement(
            'ALTER TABLE report_source_snapshots ADD CONSTRAINT report_source_snapshots_source_version_check '
            ."CHECK (source_version IS NULL OR source_version ~ '^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$')",
        );
        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON report_source_snapshots '
            .'(source_kind, report_code, schema_version, organization_id, scope_identity_hash, query_hash, source_version) '
            ."WHERE status = 'ready' AND source_version IS NOT NULL",
            self::READY_IDENTITY_UNIQUE,
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf('DROP INDEX IF EXISTS %s', self::READY_IDENTITY_UNIQUE));
        DB::statement(
            'ALTER TABLE report_source_snapshots DROP CONSTRAINT IF EXISTS '
            .'report_source_snapshots_scope_identity_hash_check',
        );
        DB::statement(
            'ALTER TABLE report_source_snapshots DROP CONSTRAINT IF EXISTS '
            .'report_source_snapshots_source_version_check',
        );

        Schema::table('report_source_snapshots', function (Blueprint $table): void {
            $table->dropColumn(['scope_identity_hash', 'source_version']);
        });
    }
};
