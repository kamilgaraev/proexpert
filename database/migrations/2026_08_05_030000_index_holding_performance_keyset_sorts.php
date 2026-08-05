<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SORTS = [
        'period_start' => 'holding_performance_sort_period',
        'contributor_organization_id' => 'holding_performance_sort_org',
        'project_id' => 'holding_performance_sort_project',
        'currency' => 'holding_performance_sort_currency',
        'monetary_basis' => 'holding_performance_sort_basis',
        'contracted_minor' => 'holding_performance_sort_contracted',
        'accepted_accrual_minor' => 'holding_performance_sort_accepted',
        'cash_minor' => 'holding_performance_sort_cash',
    ];

    public function up(): void
    {
        Schema::table('holding_performance_rows', function (Blueprint $table): void {
            foreach (self::SORTS as $column => $name) {
                $table->index(['organization_id', 'snapshot_id', $column, 'row_key'], $name);
            }
        });
        Schema::table('holding_accepted_work_event_versions', function (Blueprint $table): void {
            $table->index(
                ['organization_id', 'project_id', 'occurred_at', 'recorded_at', 'id'],
                'holding_accepted_event_scope_asof',
            );
            $table->index(
                ['performance_act_id', 'recorded_at', 'id', 'occurred_at'],
                'holding_accepted_event_latest_asof',
            );
        });
        Schema::table('holding_payment_transaction_event_versions', function (Blueprint $table): void {
            $table->index(
                ['recorded_at', 'transaction_id', 'id'],
                'holding_payment_event_checkpoint_evidence',
            );
            $table->index(
                ['transaction_id', 'recorded_at', 'id', 'occurred_at'],
                'holding_payment_event_latest_capture',
            );
        });
        Schema::table('holding_allocation_fact_versions', function (Blueprint $table): void {
            $table->index(
                [
                    'organization_id',
                    'source_type',
                    'monetary_basis',
                    'source_version',
                    'project_id',
                    'business_effective_at',
                    'recorded_at',
                ],
                'holding_fact_projection_coverage',
            );
        });
    }

    public function down(): void
    {
        Schema::table('holding_allocation_fact_versions', function (Blueprint $table): void {
            $table->dropIndex('holding_fact_projection_coverage');
        });
        Schema::table('holding_payment_transaction_event_versions', function (Blueprint $table): void {
            $table->dropIndex('holding_payment_event_latest_capture');
            $table->dropIndex('holding_payment_event_checkpoint_evidence');
        });
        Schema::table('holding_accepted_work_event_versions', function (Blueprint $table): void {
            $table->dropIndex('holding_accepted_event_latest_asof');
            $table->dropIndex('holding_accepted_event_scope_asof');
        });
        Schema::table('holding_performance_rows', function (Blueprint $table): void {
            foreach (self::SORTS as $name) {
                $table->dropIndex($name);
            }
        });
    }
};
