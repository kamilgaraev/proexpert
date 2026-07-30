<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('quality_defect_status_history', function (Blueprint $table): void {
            $table->jsonb('reporting_dimensions')->nullable();
            $table->jsonb('reporting_evidence_refs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quality_defect_status_history', function (Blueprint $table): void {
            $table->dropColumn(['reporting_dimensions', 'reporting_evidence_refs']);
        });
    }
};
