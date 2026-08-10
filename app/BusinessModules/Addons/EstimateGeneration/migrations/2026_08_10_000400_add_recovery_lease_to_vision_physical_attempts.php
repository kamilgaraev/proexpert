<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estimate_generation_vision_physical_attempts', function (Blueprint $table): void {
            $table->uuid('owner_token')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->timestampTz('wire_started_at')->nullable();
            $table->timestampTz('response_received_at')->nullable();
            $table->timestampTz('ambiguous_at')->nullable();
            $table->string('terminal_reason', 96)->nullable();
            $table->index(['state', 'lease_expires_at'], 'vision_attempt_state_lease_idx');
        });
    }

    public function down(): void {}
};
