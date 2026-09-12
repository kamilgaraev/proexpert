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
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->dropUnique('design_source_links_unique_target');
        });
        DB::statement("CREATE UNIQUE INDEX design_source_links_active_target ON design_source_links (source_version_id, COALESCE(source_sheet_id, -1), COALESCE(source_element_id, -1), target_type, target_id) WHERE status = 'active'");
    }

    public function down(): void
    {
        Schema::table('design_source_links', function (Blueprint $table): void {
            $table->unique(['source_version_id', 'source_sheet_id', 'target_type', 'target_id'], 'design_source_links_unique_target');
        });
        DB::statement('DROP INDEX design_source_links_active_target');
    }
};
