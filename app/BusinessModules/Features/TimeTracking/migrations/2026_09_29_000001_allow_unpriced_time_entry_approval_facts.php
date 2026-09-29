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
        Schema::table('time_entry_approval_reporting_facts', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable()->change();
        });

        DB::statement('ALTER TABLE time_entry_approval_reporting_facts ADD CONSTRAINT time_entry_unpriced_currency_check CHECK (currency IS NOT NULL OR (hourly_rate_minor IS NULL AND cost_minor IS NULL AND quality_status = \'partial\'))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE time_entry_approval_reporting_facts DROP CONSTRAINT time_entry_unpriced_currency_check');

        Schema::table('time_entry_approval_reporting_facts', function (Blueprint $table): void {
            $table->char('currency', 3)->nullable(false)->change();
        });
    }
};
