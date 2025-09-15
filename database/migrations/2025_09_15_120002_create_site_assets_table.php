<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holding_site_id')->constrained('holding_sites')->onDelete('cascade');
            $table->string('filename'); // original filename
            $table->string('storage_path'); // path in S3/storage
            $table->string('public_url'); // CDN URL for frontend
            $table->string('mime_type');
            $table->bigInteger('file_size'); // bytes
            $table->json('metadata')->nullable(); // dimensions, alt text, etc.
            $table->string('asset_type'); // image, document, video, icon
            $table->string('usage_context')->nullable(); // hero, logo, gallery, etc.
            $table->boolean('is_optimized')->default(false); // processed/optimized
            $table->json('optimized_variants')->nullable(); // different sizes/formats
            $table->foreignId('uploaded_by_user_id')->constrained('users')->onDelete('restrict');
            $table->timestamps();
            
            $table->index(['holding_site_id', 'asset_type']);
            $table->index(['holding_site_id', 'usage_context']);
            $table->index(['mime_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_assets');
    }
};
