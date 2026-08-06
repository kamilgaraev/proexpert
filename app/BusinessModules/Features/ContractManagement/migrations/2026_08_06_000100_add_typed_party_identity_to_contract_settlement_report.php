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
        Schema::table('contract_settlement_source_facts', function (Blueprint $table): void {
            $table->string('party_type', 16)->nullable()->after('party_id');
            $table->string('party_key', 96)->nullable()->after('party_type');
            $table->string('party_label', 255)->nullable()->after('party_key');
        });
        Schema::table('contract_settlement_exposure_rows', function (Blueprint $table): void {
            $table->string('party_type', 16)->nullable()->after('party_id');
            $table->string('party_key', 96)->nullable()->after('party_type');
            $table->string('party_label', 255)->nullable()->after('party_key');
            $table->index(
                ['organization_id', 'snapshot_id', 'party_label', 'row_key'],
                'contract_settlement_row_party_idx',
            );
        });

        DB::statement(<<<'SQL'
ALTER TABLE contract_settlement_source_facts
ADD CONSTRAINT contract_settlement_source_party_identity_check
CHECK (
    party_label IS NOT NULL
    AND btrim(party_label) <> ''
    AND ((party_id IS NULL AND party_type IS NULL AND party_key IS NULL)
    OR (
        party_id IS NOT NULL
        AND party_id > 0
        AND party_type IN ('contractor', 'supplier')
        AND party_key = party_type || ':' || party_id::text
    ))
) NOT VALID
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE contract_settlement_exposure_rows
ADD CONSTRAINT contract_settlement_row_party_identity_check
CHECK (
    party_label IS NOT NULL
    AND btrim(party_label) <> ''
    AND ((party_id IS NULL AND party_type IS NULL AND party_key IS NULL)
    OR (
        party_id IS NOT NULL
        AND party_id > 0
        AND party_type IN ('contractor', 'supplier')
        AND party_key = party_type || ':' || party_id::text
    ))
) NOT VALID
SQL);
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE contract_settlement_source_facts '
            .'DROP CONSTRAINT IF EXISTS contract_settlement_source_party_identity_check',
        );
        DB::statement(
            'ALTER TABLE contract_settlement_exposure_rows '
            .'DROP CONSTRAINT IF EXISTS contract_settlement_row_party_identity_check',
        );
        Schema::table('contract_settlement_exposure_rows', function (Blueprint $table): void {
            $table->dropIndex('contract_settlement_row_party_idx');
            $table->dropColumn(['party_type', 'party_key', 'party_label']);
        });
        Schema::table('contract_settlement_source_facts', function (Blueprint $table): void {
            $table->dropColumn(['party_type', 'party_key', 'party_label']);
        });
    }
};
