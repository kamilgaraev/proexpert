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
        Schema::table('estimate_revision_operations', function (Blueprint $table): void {
            $table->string('operation_type', 20)->default('revision');
            $table->foreignId('target_version_id')->nullable()->constrained('estimate_versions');
            $table->unsignedBigInteger('source_version_id')->nullable()->change();
        });
        DB::table('estimates')->whereNotNull('structure_cache_path')->whereExists(function ($query): void {
            $query->selectRaw('1')->from('estimate_items')
                ->whereColumn('estimate_items.estimate_id', 'estimates.id')
                ->whereNotNull('estimate_items.deleted_at')
                ->whereColumn('estimate_items.deleted_at', '>=', 'estimates.updated_at');
        })->update(['structure_cache_path' => null]);
    }

    public function down(): void
    {
        Schema::table('estimate_revision_operations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('target_version_id');
            $table->dropColumn('operation_type');
        });
    }
};
