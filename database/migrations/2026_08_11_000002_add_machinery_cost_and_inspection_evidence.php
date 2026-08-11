<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->decimal('hourly_rate_snapshot', 15, 2)->nullable()->after('actual_hours');
            $table->jsonb('cost_evidence')->nullable()->after('hourly_rate_snapshot');
        });
        Schema::table('machinery_downtimes', function (Blueprint $table): void {
            $table->string('reason_code', 80)->nullable()->after('reason');
            $table->string('reason_original', 255)->nullable()->after('reason_code');
        });
        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->string('fuel_type_code', 80)->nullable()->after('fuel_type');
            $table->string('fuel_type_original', 255)->nullable()->after('fuel_type_code');
            $table->string('unit_code', 20)->nullable()->after('unit');
            $table->string('unit_original', 80)->nullable()->after('unit_code');
        });

        Schema::create('machinery_defects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('organization_asset_id')->nullable()->constrained('organization_assets')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('defect_code', 80);
            $table->string('severity', 32);
            $table->string('status', 32)->default('open');
            $table->text('description');
            $table->timestamp('reported_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'asset_id', 'status']);
        });

        Schema::create('maintenance_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('maintenance_order_id')->constrained('machinery_maintenance_orders')->cascadeOnDelete();
            $table->foreignId('organization_asset_id')->nullable()->constrained('organization_assets')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('result', 32);
            $table->text('notes')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
            $table->unique('maintenance_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_inspections');
        Schema::dropIfExists('machinery_defects');
        Schema::table('machinery_fuel_issues', function (Blueprint $table): void {
            $table->dropColumn(['fuel_type_code', 'fuel_type_original', 'unit_code', 'unit_original']);
        });
        Schema::table('machinery_downtimes', function (Blueprint $table): void {
            $table->dropColumn(['reason_code', 'reason_original']);
        });
        Schema::table('machinery_shift_reports', function (Blueprint $table): void {
            $table->dropColumn(['hourly_rate_snapshot', 'cost_evidence']);
        });
    }
};
