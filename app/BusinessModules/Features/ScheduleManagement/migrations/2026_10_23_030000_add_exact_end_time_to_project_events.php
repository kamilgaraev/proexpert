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
        Schema::table('project_events', function (Blueprint $table): void {
            $table->time('end_time')->nullable();
            $table->integer('duration_minutes')->nullable()->default(60)->change();
        });
    }

    public function down(): void
    {
        DB::table('project_events')->whereNull('duration_minutes')->update(['duration_minutes' => 0]);
        Schema::table('project_events', function (Blueprint $table): void {
            $table->dropColumn('end_time');
            $table->integer('duration_minutes')->nullable(false)->default(60)->change();
        });
    }
};
