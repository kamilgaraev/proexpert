<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_defect_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quality_defect_id')->constrained('quality_defects')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32);
            $table->text('url');
            $table->string('caption')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'quality_defect_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defect_photos');
    }
};
