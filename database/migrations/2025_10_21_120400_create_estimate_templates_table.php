<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            
            $table->string('name');
            $table->text('description')->nullable();
            
            $table->string('work_type_category')->nullable();
            $table->json('template_structure');
            
            $table->boolean('is_public')->default(false);
            $table->integer('usage_count')->default(0);
            
            $table->foreignId('created_by_user_id')->constrained('users')->onDelete('cascade');
            
            $table->timestamps();
            
            $table->index(['organization_id', 'work_type_category']);
            $table->index(['organization_id', 'is_public']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_templates');
    }
};

