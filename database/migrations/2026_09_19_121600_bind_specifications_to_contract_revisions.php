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
        Schema::table('specifications', function (Blueprint $table): void {
            $table->foreignId('builder_revision_id')->nullable()->unique()->constrained('contract_builder_revisions')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE FUNCTION guard_revision_specification() RETURNS trigger AS $$
BEGIN
    IF OLD.builder_revision_id IS NOT NULL THEN RAISE EXCEPTION 'revision_specification_immutable'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER revision_specification_guard BEFORE UPDATE OR DELETE ON specifications FOR EACH ROW EXECUTE FUNCTION guard_revision_specification();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS revision_specification_guard ON specifications; DROP FUNCTION IF EXISTS guard_revision_specification();');
        Schema::table('specifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('builder_revision_id');
        });
    }
};
