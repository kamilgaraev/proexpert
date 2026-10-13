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
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Session creation receipts require PostgreSQL.');
        }
        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->uuid('creation_request_id')->nullable();
            $table->char('creation_request_hash', 64)->nullable();
            $table->unique(['organization_id', 'user_id', 'creation_request_id'], 'eg_session_creation_request_uq');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE estimate_generation_sessions ADD CONSTRAINT eg_session_creation_receipt_ck CHECK (
 (creation_request_id IS NULL AND creation_request_hash IS NULL)
 OR (creation_request_id IS NOT NULL AND creation_request_hash IS NOT NULL AND creation_request_hash ~ '^[a-f0-9]{64}$'));
CREATE FUNCTION eg_session_creation_receipt_guard() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF (OLD.creation_request_id IS NOT NULL OR NEW.creation_request_id IS NOT NULL) AND
  ROW(OLD.creation_request_id,OLD.creation_request_hash,OLD.organization_id,OLD.project_id,OLD.user_id)
  IS DISTINCT FROM ROW(NEW.creation_request_id,NEW.creation_request_hash,NEW.organization_id,NEW.project_id,NEW.user_id)
 THEN RAISE EXCEPTION 'estimate_generation.session_creation_receipt_immutable'; END IF;
 RETURN NEW;
END;
$$;
CREATE TRIGGER eg_session_creation_receipt_guard_trg BEFORE UPDATE ON estimate_generation_sessions
FOR EACH ROW EXECUTE FUNCTION eg_session_creation_receipt_guard();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS eg_session_creation_receipt_guard_trg ON estimate_generation_sessions');
        DB::statement('DROP FUNCTION IF EXISTS eg_session_creation_receipt_guard()');
        Schema::table('estimate_generation_sessions', function (Blueprint $table): void {
            $table->dropUnique('eg_session_creation_request_uq');
            $table->dropColumn(['creation_request_id', 'creation_request_hash']);
        });
    }
};
