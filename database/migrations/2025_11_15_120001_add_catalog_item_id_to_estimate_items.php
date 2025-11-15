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
            $table->foreignId('catalog_item_id')
                ->nullable()
                ->after('estimate_section_id')
                ->constrained('estimate_position_catalog')
                ->onDelete('set null');
            
            $table->index('catalog_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('estimate_items', function (Blueprint $table) {
            $table->dropForeign(['catalog_item_id']);
            $table->dropIndex(['catalog_item_id']);
            $table->dropColumn('catalog_item_id');
        });
    }
};

