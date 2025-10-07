<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('organization_id')->nullable();
            
            $table->string('notification_type', 100);
            
            $table->json('enabled_channels');
            
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            
            $table->json('frequency_limit')->nullable();
            
            $table->json('metadata')->nullable();
            
            $table->timestamps();
            
            $table->unique(['user_id', 'organization_id', 'notification_type'], 'user_org_type_unique');
            
            $table->index('user_id');
            $table->index('organization_id');
            $table->index('notification_type');
            
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
                
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};

