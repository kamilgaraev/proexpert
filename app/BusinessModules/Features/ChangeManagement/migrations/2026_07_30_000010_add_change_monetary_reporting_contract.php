<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_management_change_requests', function (Blueprint $table): void {
            $table->char('reporting_currency', 3)->nullable();
            $table->unsignedBigInteger('reporting_contract_project_allocation_id')->nullable();
            $table->bigInteger('contingency_opening_minor')->nullable();
            $table->bigInteger('contingency_allocation_minor')->nullable();
            $table->bigInteger('contingency_release_minor')->nullable();
        });

        Schema::table('change_management_approvals', function (Blueprint $table): void {
            $table->bigInteger('approved_cost_minor')->nullable();
            $table->char('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('change_management_approvals', function (Blueprint $table): void {
            $table->dropColumn(['approved_cost_minor', 'currency']);
        });
        Schema::table('change_management_change_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'reporting_currency',
                'reporting_contract_project_allocation_id',
                'contingency_opening_minor',
                'contingency_allocation_minor',
                'contingency_release_minor',
            ]);
        });
    }
};
