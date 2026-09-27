<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_labor_output_entries', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->char('payload_fingerprint', 64)->nullable();
            $table->unique(
                ['organization_id', 'recorded_by_user_id', 'idempotency_key'],
                'production_labor_output_mobile_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('production_labor_output_entries', function (Blueprint $table): void {
            $table->dropUnique('production_labor_output_mobile_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'payload_fingerprint']);
        });
    }
};
