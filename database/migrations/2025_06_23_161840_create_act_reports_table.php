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
        Schema::create('act_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('performance_act_id')->constrained('contract_performance_acts')->onDelete('cascade');
            $table->string('report_number')->unique();
            $table->string('title');
            $table->enum('format', ['pdf', 'excel']);
            $table->string('file_path');
            $table->string('s3_key')->nullable();
            $table->integer('file_size')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            
            $table->index(['organization_id', 'performance_act_id']);
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('act_reports');
    }
};
