<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (!Schema::hasColumn('estimate_items', 'material_id')) {
                $table->foreignId('material_id')
                    ->nullable()
                    ->after('work_type_id')
                    ->constrained('materials')
                    ->nullOnDelete();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            if (Schema::hasColumn('estimate_items', 'material_id')) {
                $table->dropForeign(['material_id']);
                $table->dropColumn('material_id');
            }
        });
    }
};
