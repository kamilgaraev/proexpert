<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->foreignId('estimate_version_id')
                ->nullable()
                ->after('project_id')
                ->constrained('estimate_versions')
                ->restrictOnDelete();
            $table->decimal('vat_rate', 5, 2)->default(0)->after('amount');
            $table->decimal('vat_amount', 15, 2)->default(0)->after('vat_rate');
            $table->decimal('amount_without_vat', 15, 2)->default(0)->after('vat_amount');
        });

        Schema::table('performance_act_lines', function (Blueprint $table): void {
            $table->foreignId('estimate_version_id')
                ->nullable()
                ->after('estimate_item_id')
                ->constrained('estimate_versions')
                ->restrictOnDelete();
            $table->foreignId('variation_order_id')
                ->nullable()
                ->after('estimate_version_id')
                ->constrained('change_management_variation_orders')
                ->restrictOnDelete();
            $table->jsonb('basis_snapshot')->nullable()->after('manual_reason');
            $table->index('variation_order_id', 'performance_act_lines_variation_order_idx');
        });

        app(\App\Services\Acting\LegacyPerformanceActBasisBackfillService::class)->backfill();
    }

    public function down(): void
    {
        Schema::table('performance_act_lines', function (Blueprint $table): void {
            $table->dropIndex('performance_act_lines_variation_order_idx');
            $table->dropConstrainedForeignId('variation_order_id');
            $table->dropConstrainedForeignId('estimate_version_id');
            $table->dropColumn('basis_snapshot');
        });

        Schema::table('contract_performance_acts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('estimate_version_id');
            $table->dropColumn(['vat_rate', 'vat_amount', 'amount_without_vat']);
        });
    }
};
