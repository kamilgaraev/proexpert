<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_materials', function (Blueprint $table): void {
            $table->foreignId('project_material_delivery_id')
                ->nullable()
                ->after('estimate_item_id')
                ->constrained('project_material_deliveries')
                ->nullOnDelete();

            $table->index('project_material_delivery_id', 'journal_materials_project_delivery_idx');
        });
    }

    public function down(): void
    {
        Schema::table('journal_materials', function (Blueprint $table): void {
            $table->dropForeign(['project_material_delivery_id']);
            $table->dropIndex('journal_materials_project_delivery_idx');
            $table->dropColumn('project_material_delivery_id');
        });
    }
};
