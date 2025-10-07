<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_webhooks', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('organization_id');
            
            $table->string('name', 255);
            
            $table->string('url', 500);
            
            $table->string('secret', 255)->nullable();
            
            $table->json('events');
            
            $table->json('headers')->nullable();
            
            $table->boolean('is_active')->default(true);
            
            $table->integer('timeout')->default(30);
            
            $table->integer('max_retries')->default(3);
            
            $table->timestamp('last_triggered_at')->nullable();
            
            $table->integer('success_count')->default(0);
            $table->integer('failure_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('organization_id');
            $table->index('is_active');
            
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_webhooks');
    }
};

