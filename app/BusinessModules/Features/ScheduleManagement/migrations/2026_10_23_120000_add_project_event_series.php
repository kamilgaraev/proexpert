<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_event_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('root_event_id')->unique();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });

        Schema::create('project_event_series_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('series_id')->constrained('project_event_series')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('from_position');
            $table->date('anchor_date');
            $table->string('pattern')->nullable();
            $table->date('until')->nullable();
            $table->boolean('enabled')->nullable();
            $table->jsonb('template');
            $table->jsonb('interval')->nullable();
            $table->jsonb('configuration')->nullable();
            $table->timestamps();
            $table->unique(['series_id', 'revision']);
        });

        Schema::table('project_events', function (Blueprint $table): void {
            $table->foreignId('series_id')->nullable()->constrained('project_event_series')->restrictOnDelete();
            $table->unsignedInteger('series_position')->nullable();
            $table->jsonb('recurrence_overrides')->nullable();
            $table->boolean('recurrence_suppressed')->default(false);
            $table->unique(['series_id', 'series_position']);
        });

        Schema::create('project_event_series_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('series_id')->constrained('project_event_series')->restrictOnDelete();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->uuid('operation_id');
            $table->string('request_hash', 64);
            $table->jsonb('result');
            $table->timestamps();
            $table->unique(['organization_id', 'operation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_event_series_operations');
        Schema::table('project_events', function (Blueprint $table): void {
            $table->dropForeign(['series_id']);
            $table->dropUnique(['series_id', 'series_position']);
            $table->dropColumn(['series_id', 'series_position', 'recurrence_overrides', 'recurrence_suppressed']);
        });
        Schema::dropIfExists('project_event_series_segments');
        Schema::dropIfExists('project_event_series');
    }
};
