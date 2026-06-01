<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('design_model_derivatives', function (Blueprint $table): void {
            $table->unsignedSmallInteger('progress_percent')->default(0)->after('status');
            $table->text('processing_stage')->nullable()->after('progress_percent');
            $table->timestampTz('processing_started_at')->nullable()->after('prepared_at');
            $table->timestampTz('processing_finished_at')->nullable()->after('processing_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('design_model_derivatives', function (Blueprint $table): void {
            $table->dropColumn([
                'progress_percent',
                'processing_stage',
                'processing_started_at',
                'processing_finished_at',
            ]);
        });
    }
};
