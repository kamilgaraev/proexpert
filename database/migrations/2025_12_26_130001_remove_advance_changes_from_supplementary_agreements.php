<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->dropColumn('advance_changes');
        });
    }

    public function down(): void
    {
        Schema::table('supplementary_agreements', function (Blueprint $table) {
            $table->jsonb('advance_changes')->nullable();
        });

        DB::statement('CREATE INDEX IF NOT EXISTS idx_supplementary_agreements_advance_changes ON supplementary_agreements USING GIN (advance_changes)');
    }
};

