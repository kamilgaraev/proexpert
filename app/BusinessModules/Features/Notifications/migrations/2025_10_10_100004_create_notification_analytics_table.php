<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_analytics', function (Blueprint $table) {
            $table->id();
            
            $table->uuid('notification_id');
            
            $table->string('channel', 50);
            
            $table->enum('status', [
                'pending',
                'queued',
                'sending',
                'sent',
                'delivered',
                'failed',
                'opened',
                'clicked',
                'bounced',
                'complained'
            ])->default('pending');
            
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            
            $table->text('error_message')->nullable();
            
            $table->integer('retry_count')->default(0);
            
            $table->string('tracking_id', 100)->nullable()->unique();
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->index('notification_id');
            $table->index(['channel', 'status']);
            $table->index('tracking_id');
            $table->index('sent_at');
            $table->index('delivered_at');
            $table->index('opened_at');
            
            $table->foreign('notification_id')
                ->references('id')
                ->on('notifications')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_analytics');
    }
};

