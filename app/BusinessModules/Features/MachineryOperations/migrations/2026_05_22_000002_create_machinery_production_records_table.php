<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machinery_production_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('machinery_assets')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('shift_report_id')->nullable()->constrained('machinery_shift_reports')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20);
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'project_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machinery_production_records');
    }
};
