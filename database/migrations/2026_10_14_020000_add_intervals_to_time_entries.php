<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->timestampTz('start_at', 6)->nullable();
            $table->timestampTz('end_at', 6)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('timezone_requires_reconciliation')->default(false);
            $table->decimal('net_minutes', 20, 10)->nullable();
            $table->unsignedBigInteger('net_microseconds')->nullable();
            $table->json('break_intervals')->nullable();
            $table->index(['organization_id', 'user_id', 'start_at', 'end_at'], 'time_entries_interval_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropIndex('time_entries_interval_lookup');
            $table->dropColumn(['start_at', 'end_at', 'timezone', 'timezone_requires_reconciliation', 'net_minutes', 'net_microseconds', 'break_intervals']);
        });
    }
};
