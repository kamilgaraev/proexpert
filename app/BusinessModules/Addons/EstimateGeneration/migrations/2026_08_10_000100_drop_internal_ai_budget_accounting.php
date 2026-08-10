<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'DROP FUNCTION IF EXISTS eg_claim_ai_budget_wire(uuid)',
            'DROP FUNCTION IF EXISTS eg_mark_ai_budget_sent(uuid)',
            'DROP FUNCTION IF EXISTS eg_settle_ai_budget(uuid, numeric, text)',
            'DROP FUNCTION IF EXISTS eg_release_ai_budget(uuid)',
            'DROP FUNCTION IF EXISTS eg_mark_ai_budget_reconciliation(uuid)',
            'DROP FUNCTION IF EXISTS eg_reconcile_expired_ai_budgets(integer)',
            'DROP FUNCTION IF EXISTS eg_reserve_ai_budget(uuid, bigint, bigint, bigint, bigint, numeric, text, jsonb)',
            'DROP FUNCTION IF EXISTS eg_reserve_ai_budget(uuid, uuid, bigint, bigint, bigint, bigint, numeric, text, jsonb, text)',
        ] as $statement) {
            DB::statement($statement);
        }

        Schema::dropIfExists('estimate_generation_ai_budget_reservations');
    }

    public function down(): void
    {
        throw new \RuntimeException('Internal AI budget accounting cleanup is irreversible.');
    }
};
