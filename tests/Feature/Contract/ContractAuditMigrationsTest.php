<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ContractAuditMigrationsTest extends TestCase
{
    public function test_empty_schema_can_roll_back_and_reapply_all_audit_migrations(): void
    {
        $names = ['170000_add_parties_to_contract_builder_drafts', '170500_add_contract_party_keys',
            '171000_expand_contract_builder_party_confirmations', '172000_add_contract_proposal_recipients',
            '173000_add_contract_proposal_review', '175000_add_direct_contract_parties', '176000_align_contract_archive_party_snapshots'];
        $migrations = array_map(static fn (string $name) => require database_path('migrations/2026_10_11_'.$name.'.php'), $names);
        foreach (array_reverse($migrations) as $migration) $migration->down();
        self::assertFalse(Schema::hasColumn('contracts', 'direct_party'));
        self::assertFalse(Schema::hasColumn('contract_builder_drafts', 'parties'));
        self::assertFalse(Schema::hasColumn('contract_parties', 'party_key'));
        self::assertFalse(Schema::hasTable('contract_proposal_comments'));
        self::assertFalse(Schema::hasTable('contract_builder_proposal_recipients'));
        foreach ($migrations as $migration) $migration->up();
        self::assertTrue(Schema::hasColumn('contracts', 'direct_party'));
        self::assertTrue(Schema::hasColumn('contract_builder_drafts', 'parties'));
        self::assertTrue(Schema::hasColumn('contract_parties', 'party_key'));
        self::assertTrue(Schema::hasColumn('contract_revision_confirmations', 'party_key'));
        self::assertTrue(Schema::hasTable('contract_proposal_comments'));
        self::assertTrue(Schema::hasTable('contract_proposal_clause_decisions'));
        self::assertTrue(Schema::hasTable('contract_builder_proposal_recipients'));
        foreach (['contract_party_key_default', 'contract_proposal_recipient_guard', 'contract_proposal_recipient_review_guard'] as $trigger) {
            self::assertTrue(DB::table('pg_trigger')->where('tgname', $trigger)->exists(), $trigger);
        }
    }
}
