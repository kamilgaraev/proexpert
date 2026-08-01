<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_workspace_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('owner_id');
            $table->jsonb('recent_report_codes')->default('[]');
            $table->jsonb('favourite_report_codes')->default('[]');
            $table->jsonb('display_preferences')->default('{}');
            $table->timestampsTz();
            $table->unique(
                ['organization_id', 'owner_id'],
                'report_workspace_preferences_owner_unique',
            );
            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_workspace_preferences');
    }
};
