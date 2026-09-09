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
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('normalized_name', 100);
            $table->timestamps();
            $table->unique(['organization_id', 'normalized_name']);
        });

        DB::table('materials')->select(['id', 'organization_id', 'category', 'additional_properties'])
            ->whereNotNull('organization_id')->orderBy('id')->chunkById(500, function ($materials): void {
                foreach ($materials as $material) {
                    $properties = json_decode($material->additional_properties ?? '{}', true) ?? [];
                    $name = trim(preg_replace('/\s+/u', ' ', (string) ($properties['asset_category'] ?? $material->category ?? '')) ?? '');
                    if ($name === '') {
                        continue;
                    }
                    $name = mb_substr($name, 0, 100);
                    $key = mb_strtolower($name, 'UTF-8');
                    DB::table('asset_categories')->insertOrIgnore([
                        'organization_id' => $material->organization_id,
                        'name' => $name,
                        'normalized_name' => $key,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $properties['asset_category'] = DB::table('asset_categories')
                        ->where('organization_id', $material->organization_id)
                        ->where('normalized_name', $key)->value('name');
                    DB::table('materials')->where('id', $material->id)->update([
                        'additional_properties' => json_encode($properties, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
