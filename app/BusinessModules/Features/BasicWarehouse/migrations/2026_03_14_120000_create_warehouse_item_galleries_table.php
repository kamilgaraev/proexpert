<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_item_galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained('organization_warehouses')->onDelete('cascade');
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['organization_id', 'warehouse_id', 'material_id'], 'warehouse_item_gallery_unique');
            $table->index(['warehouse_id', 'material_id'], 'warehouse_item_gallery_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_item_galleries');
    }
};
