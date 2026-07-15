<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_commercial_accounts', function (Blueprint $table): void {
            $table->index(
                ['status', 'current_period_end_at', 'id'],
                'commercial_accounts_renewal_due_idx',
            );
        });
        Schema::table('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->index(
                ['commercial_account_id', 'organization_id', 'target_period_start_at', 'status', 'next_attempt_at'],
                'commercial_renewal_actionable_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('commercial_renewal_cycles', function (Blueprint $table): void {
            $table->dropIndex('commercial_renewal_actionable_idx');
        });
        Schema::table('organization_commercial_accounts', function (Blueprint $table): void {
            $table->dropIndex('commercial_accounts_renewal_due_idx');
        });
    }
};
