<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
            $table->string('storage_version_id', 255);
            $table->string('storage_etag', 255);
            $table->char('storage_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type', 255);
            $table->string('caption')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'quality_defect_id', 'type']);
        });
        DB::statement("ALTER TABLE quality_defect_photos ADD CONSTRAINT quality_defect_photo_storage_identity_check CHECK (url LIKE 'org-%/%' AND url NOT LIKE '%://%' AND storage_sha256 ~ '^[a-f0-9]{64}$' AND size_bytes > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_defect_photos');
    }
};
