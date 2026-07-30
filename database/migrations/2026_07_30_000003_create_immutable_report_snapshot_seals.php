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
        Schema::create('report_snapshot_seals', function (Blueprint $table): void {
            $table->string('snapshot_kind', 128);
            $table->string('snapshot_id', 64);
            $table->string('algorithm', 32);
            $table->string('key_id', 128);
            $table->char('sealed_payload_hash', 64);
            $table->string('signature', 128);
            $table->timestampTz('generated_at');
            $table->timestampTz('sealed_at');
            $table->timestampTz('created_at');
            $table->primary(['snapshot_kind', 'snapshot_id']);
        });
        Schema::create('report_snapshot_seal_backfills', function (Blueprint $table): void {
            $table->string('snapshot_kind', 128)->primary();
            $table->string('status', 16);
            $table->unsignedBigInteger('source_count')->default(0);
            $table->unsignedBigInteger('sealed_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);
            $table->char('failure_fingerprint', 64)->nullable();
            $table->string('remediation', 255)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('updated_at');
        });
        DB::statement("ALTER TABLE report_snapshot_seals ADD CONSTRAINT report_snapshot_seals_crypto_check CHECK (algorithm = 'ed25519-sha256' AND key_id ~ '^[a-z][a-z0-9_.:-]{2,127}$' AND sealed_payload_hash ~ '^[a-f0-9]{64}$' AND signature ~ '^[A-Za-z0-9_-]{86}$')");
        DB::statement("ALTER TABLE report_snapshot_seal_backfills ADD CONSTRAINT report_snapshot_seal_backfills_status_check CHECK (status IN ('running', 'ready', 'failed'))");
        DB::statement("ALTER TABLE report_snapshot_seal_backfills ADD CONSTRAINT report_snapshot_seal_backfills_coverage_check CHECK (sealed_count <= source_count AND ((status = 'ready' AND sealed_count = source_count AND completed_at IS NOT NULL AND failure_fingerprint IS NULL AND remediation IS NULL) OR (status = 'failed' AND completed_at IS NOT NULL AND failure_fingerprint IS NOT NULL AND remediation IS NOT NULL) OR status = 'running'))");
        DB::unprepared(<<<'SQL'
CREATE FUNCTION immutable_report_snapshot_seal_guard() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'report_snapshot_seal_immutable' USING ERRCODE = '55000';
END;
$$;
CREATE TRIGGER report_snapshot_seals_immutable
BEFORE UPDATE OR DELETE ON report_snapshot_seals
FOR EACH ROW EXECUTE FUNCTION immutable_report_snapshot_seal_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS immutable_report_snapshot_seal_guard() CASCADE');
        Schema::dropIfExists('report_snapshot_seal_backfills');
        Schema::dropIfExists('report_snapshot_seals');
    }
};
