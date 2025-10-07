<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_usage_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('material_id');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 10, 2);
            $table->string('usage_type')->default('consumption');
            $table->text('notes')->nullable();
            $table->timestamp('used_at');
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->onDelete('cascade');
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->foreign('material_id')->references('id')->on('materials')->onDelete('cascade');
            
            $table->index(['organization_id', 'used_at']);
            $table->index(['material_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_usage_history');
    }
};

