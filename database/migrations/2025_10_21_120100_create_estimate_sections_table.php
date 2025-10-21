<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estimate_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_section_id')->nullable()->constrained('estimate_sections')->onDelete('cascade');
            
            $table->string('section_number');
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->integer('sort_order')->default(0);
            $table->boolean('is_summary')->default(false);
            
            $table->decimal('section_total_amount', 15, 2)->default(0);
            
            $table->timestamps();
            
            $table->index(['estimate_id', 'parent_section_id']);
            $table->index(['estimate_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_sections');
    }
};

