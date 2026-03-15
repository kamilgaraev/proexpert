<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_cameras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('zone')->nullable();
            $table->string('source_type', 32)->default('rtsp');
            $table->text('source_url');
            $table->text('playback_url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->text('stream_path')->nullable();
            $table->string('transport_protocol', 16)->default('tcp');
            $table->string('status', 32)->default('pending');
            $table->text('status_message')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampTz('last_online_at')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->jsonb('settings')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['organization_id', 'project_id']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_cameras');
    }
};
