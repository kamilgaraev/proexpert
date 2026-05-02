<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_approval_policies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->decimal('non_lowest_delta_amount', 15, 2)->default(0);
            $table->decimal('non_lowest_delta_percent', 8, 2)->default(0);
            $table->decimal('budget_exceed_amount', 15, 2)->default(0);
            $table->boolean('external_supplier_requires_identity')->default(true);
            $table->boolean('prevent_requester_approval')->default(true);
            $table->boolean('prevent_selector_approval')->default(true);
            $table->boolean('prevent_intake_author_approval')->default(true);
            $table->string('required_approval_permission', 150)->default('procurement.approvals.resolve');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('organization_id');
        });

        Schema::table('procurement_approvals', function (Blueprint $table): void {
            if (!Schema::hasColumn('procurement_approvals', 'approval_policy_id')) {
                $table->foreignId('approval_policy_id')
                    ->nullable()
                    ->after('organization_id')
                    ->constrained('procurement_approval_policies')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('procurement_approvals', function (Blueprint $table): void {
            if (Schema::hasColumn('procurement_approvals', 'approval_policy_id')) {
                $table->dropConstrainedForeignId('approval_policy_id');
            }
        });

        Schema::dropIfExists('procurement_approval_policies');
    }
};
