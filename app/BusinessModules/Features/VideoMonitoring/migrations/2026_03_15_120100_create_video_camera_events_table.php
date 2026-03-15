<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_camera_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('camera_id')->constrained('video_cameras')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('severity', 24)->default('info');
            $table->text('message');
            $table->jsonb('payload')->nullable();
            $table->timestampTz('occurred_at');

            $table->index(['project_id', 'occurred_at']);
            $table->index(['camera_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_camera_events');
    }
};
