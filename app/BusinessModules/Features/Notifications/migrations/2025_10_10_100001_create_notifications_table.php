<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            
            $table->string('type');
            $table->morphs('notifiable');
            
            $table->unsignedBigInteger('organization_id')->nullable();
            
            $table->string('notification_type', 50)->default('system');
            
            $table->enum('priority', ['critical', 'high', 'normal', 'low'])->default('normal');
            
            $table->json('channels');
            
            $table->json('delivery_status')->nullable();
            
            $table->json('data');
            
            $table->json('metadata')->nullable();
            
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            $table->index(['notifiable_type', 'notifiable_id']);
            $table->index('organization_id');
            $table->index('notification_type');
            $table->index('priority');
            $table->index('read_at');
            $table->index('created_at');
            
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

