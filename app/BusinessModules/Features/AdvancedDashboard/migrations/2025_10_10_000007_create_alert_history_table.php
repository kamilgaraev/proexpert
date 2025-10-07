<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id');
            $table->string('status')->default('triggered');
            $table->json('trigger_data')->nullable();
            $table->decimal('trigger_value', 10, 2)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamps();

            $table->foreign('alert_id')->references('id')->on('dashboard_alerts')->onDelete('cascade');
            $table->foreign('resolved_by')->references('id')->on('users')->onDelete('set null');
            
            $table->index(['alert_id', 'triggered_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_history');
    }
};

