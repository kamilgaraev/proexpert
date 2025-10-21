<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_item_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_item_id')->constrained()->onDelete('cascade');
            
            $table->enum('resource_type', ['material', 'labor', 'equipment', 'overhead', 'other'])->default('material');
            $table->foreignId('material_id')->nullable()->constrained()->onDelete('set null');
            
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->foreignId('measurement_unit_id')->nullable()->constrained()->onDelete('set null');
            
            $table->decimal('quantity_per_unit', 15, 4)->default(0);
            $table->decimal('total_quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            
            $table->timestamps();
            
            $table->index(['estimate_item_id', 'resource_type']);
            $table->index(['material_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_item_resources');
    }
};

