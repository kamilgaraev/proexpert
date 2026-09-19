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
        Schema::create('contract_builder_legacy_adoptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contract_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('fingerprint', 64);
            $table->text('basis');
            $table->jsonb('baseline');
            $table->timestampTz('created_at');
        });
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->foreignId('legacy_adoption_id')->nullable()->unique()->constrained('contract_builder_legacy_adoptions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_contract_builder_legacy_adoption() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'contract_builder_legacy_adoption_immutable';
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER contract_builder_legacy_adoption_guard BEFORE UPDATE OR DELETE ON contract_builder_legacy_adoptions
FOR EACH ROW EXECUTE FUNCTION guard_contract_builder_legacy_adoption();
SQL);
    }

    public function down(): void
    {
        Schema::table('contract_builder_instances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legacy_adoption_id');
        });
        Schema::dropIfExists('contract_builder_legacy_adoptions');
        DB::unprepared('DROP FUNCTION IF EXISTS guard_contract_builder_legacy_adoption();');
    }
};
