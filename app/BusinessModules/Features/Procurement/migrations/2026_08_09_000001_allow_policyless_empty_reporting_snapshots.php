<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE supply_reliability_snapshots '
            .'ALTER COLUMN policy_version_id DROP NOT NULL',
        );
        DB::statement(
            'ALTER TABLE procurement_cycle_snapshots '
            .'ALTER COLUMN policy_version_id DROP NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE supply_reliability_snapshots '
            .'ALTER COLUMN policy_version_id SET NOT NULL',
        );
        DB::statement(
            'ALTER TABLE procurement_cycle_snapshots '
            .'ALTER COLUMN policy_version_id SET NOT NULL',
        );
    }
};
