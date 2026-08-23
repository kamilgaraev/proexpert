<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery_shift_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('organization_asset_id')->nullable()->constrained('organization_assets')->nullOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->restrictOnDelete();
            $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
            $table->foreignId('shift_report_id')->constrained('machinery_shift_reports')->restrictOnDelete();
            $table->foreignId('inspected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('inspection_type', 32);
            $table->string('result', 32);
            $table->text('notes')->nullable();
            $table->jsonb('evidence')->nullable();
            $table->jsonb('defects')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
            $table->unique(['shift_report_id', 'inspection_type'], 'machinery_shift_inspection_type_unique');
            $table->index(['organization_id', 'asset_id', 'inspected_at'], 'machinery_shift_inspection_asset_index');
        });

        Schema::table('machinery_defects', function (Blueprint $table): void {
            $table->foreignId('shift_report_id')->nullable()->after('project_id')->constrained('machinery_shift_reports')->restrictOnDelete();
            $table->foreignId('shift_inspection_id')->nullable()->after('shift_report_id')->constrained('machinery_shift_inspections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('machinery_defects', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('shift_inspection_id');
            $table->dropConstrainedForeignId('shift_report_id');
        });
        Schema::dropIfExists('machinery_shift_inspections');
    }
};
