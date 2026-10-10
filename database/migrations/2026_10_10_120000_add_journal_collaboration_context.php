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
        Schema::table('construction_journals', function (Blueprint $table): void {
            $table->foreignId('performing_organization_id')->nullable()->constrained('organizations')->restrictOnDelete();
            $table->index(['project_id', 'performing_organization_id']);
        });
        DB::table('construction_journals')->update(['performing_organization_id' => DB::raw('organization_id')]);
        Schema::table('construction_journals', function (Blueprint $table): void {
            $table->unsignedBigInteger('performing_organization_id')->nullable(false)->change();
        });
        Schema::table('journal_work_volumes', function (Blueprint $table): void {
            $table->string('work_name')->nullable();
        });
        Schema::table('journal_entry_approval_events', function (Blueprint $table): void {
            $table->foreignId('actor_organization_id')->nullable()->constrained('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_approval_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('actor_organization_id');
        });
        Schema::table('journal_work_volumes', function (Blueprint $table): void {
            $table->dropColumn('work_name');
        });
        Schema::table('construction_journals', function (Blueprint $table): void {
            $table->dropIndex(['project_id', 'performing_organization_id']);
            $table->dropConstrainedForeignId('performing_organization_id');
        });
    }
};
