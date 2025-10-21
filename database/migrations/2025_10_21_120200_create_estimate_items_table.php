<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('estimate_section_id')->nullable()->constrained()->onDelete('set null');
            
            $table->string('position_number');
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->foreignId('work_type_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('measurement_unit_id')->nullable()->constrained()->onDelete('set null');
            
            $table->decimal('quantity', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 2)->default(0);
            
            $table->decimal('direct_costs', 15, 2)->default(0);
            $table->decimal('overhead_amount', 15, 2)->default(0);
            $table->decimal('profit_amount', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            
            $table->string('justification')->nullable();
            $table->boolean('is_manual')->default(false);
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['estimate_id', 'estimate_section_id']);
            $table->index(['work_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_items');
    }
};

