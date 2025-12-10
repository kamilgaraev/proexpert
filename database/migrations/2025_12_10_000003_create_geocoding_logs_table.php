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
        Schema::create('geocoding_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('provider', 50);
            $table->text('request')->nullable();
            $table->text('response')->nullable();
            $table->boolean('success')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('set null');
            $table->index(['project_id', 'created_at'], 'idx_geocoding_logs_project');
            $table->index('provider', 'idx_geocoding_logs_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geocoding_logs');
    }
};

