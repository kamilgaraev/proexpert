<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_type_matching_dictionary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            
            $table->text('imported_text');
            $table->text('normalized_text');
            
            $table->foreignId('work_type_id')->constrained()->onDelete('cascade');
            $table->foreignId('matched_by_user_id')->nullable()->constrained('users')->onDelete('set null');
            
            $table->decimal('match_confidence', 5, 2)->default(0);
            $table->integer('usage_count')->default(0);
            $table->boolean('is_confirmed')->default(false);
            
            $table->timestamps();
            
            $table->index(['organization_id', 'normalized_text']);
            $table->index(['work_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_type_matching_dictionary');
    }
};

