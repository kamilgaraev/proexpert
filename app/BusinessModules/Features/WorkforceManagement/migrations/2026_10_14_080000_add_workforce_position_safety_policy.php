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
        Schema::table('workforce_positions', function (Blueprint $table): void {
            // NULL is explicit absence of applicability; existing positions are never backfilled.
            $table->string('safety_work_category', 80)->nullable();
            $table->foreignId('safety_work_type_id')->nullable()->constrained('work_types')->nullOnDelete();
        });
        DB::statement('ALTER TABLE workforce_positions ADD CONSTRAINT workforce_position_safety_policy_valid CHECK ((safety_work_category IS NOT NULL OR safety_work_type_id IS NULL) AND (safety_work_category IS NULL OR length(btrim(safety_work_category)) > 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE workforce_positions DROP CONSTRAINT IF EXISTS workforce_position_safety_policy_valid');
        Schema::table('workforce_positions', function (Blueprint $table): void {
            $table->dropForeign(['safety_work_type_id']);
            $table->dropColumn(['safety_work_category', 'safety_work_type_id']);
        });
    }
};
