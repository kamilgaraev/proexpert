<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_defect_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->text('comment')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');

            $table->index(['organization_id', 'quality_defect_id']);
            $table->index(['organization_id', 'to_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defect_status_history');
    }
};
