<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_source_snapshot_drill_rows', function (Blueprint $table): void {
            $table->string('sort_key', 128)->nullable()->after('ordinal');
            $table->unique(
                ['snapshot_id', 'row_key', 'column_id', 'sort_key'],
                'report_source_snapshot_drill_rows_stream_sort_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('report_source_snapshot_drill_rows', function (Blueprint $table): void {
            $table->dropUnique('report_source_snapshot_drill_rows_stream_sort_unique');
            $table->dropColumn('sort_key');
        });
    }
};
