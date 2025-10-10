<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->onDelete('cascade');
            $table->integer('year');
            $table->integer('month');
            $table->integer('requests_count')->default(0);
            $table->bigInteger('tokens_used')->default(0);
            $table->decimal('cost_rub', 10, 2)->default(0);
            $table->timestamps();
            
            $table->unique(['organization_id', 'year', 'month']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_stats');
    }
};

