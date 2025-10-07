<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            
            $table->unsignedBigInteger('organization_id')->nullable();
            
            $table->string('type', 100);
            
            $table->string('channel', 50);
            
            $table->string('name', 255);
            
            $table->string('subject', 500)->nullable();
            
            $table->text('content');
            
            $table->json('variables')->nullable();
            
            $table->string('locale', 10)->default('ru');
            
            $table->boolean('is_default')->default(false);
            
            $table->boolean('is_active')->default(true);
            
            $table->integer('version')->default(1);
            
            $table->unsignedBigInteger('parent_template_id')->nullable();
            
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index('organization_id');
            $table->index(['type', 'channel']);
            $table->index('is_default');
            $table->index('is_active');
            $table->index('locale');
            
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');
                
            $table->foreign('parent_template_id')
                ->references('id')
                ->on('notification_templates')
                ->onDelete('set null');
                
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
                
            $table->foreign('updated_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};

