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
        Schema::table('procurement_award_evidence_events', function (Blueprint $table): void {
            $table->string('selection_scope', 32)
                ->default('supplier_request')
                ->after('supplier_request_version_hash');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
ALTER TABLE procurement_award_evidence_events
ADD CONSTRAINT proc_award_event_selection_scope_check
CHECK (selection_scope IN ('supplier_request', 'purchase_request'))
SQL);

        $this->rewriteDeferredValidator($this->upReplacements());
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->rewriteDeferredValidator($this->downReplacements());
            DB::statement(<<<'SQL'
ALTER TABLE procurement_award_evidence_events
DROP CONSTRAINT IF EXISTS proc_award_event_selection_scope_check
SQL);
        }

        Schema::table('procurement_award_evidence_events', function (Blueprint $table): void {
            $table->dropColumn('selection_scope');
        });
    }

    /** @return array<int, array{string, string}> */
    private function upReplacements(): array
    {
        return [
            [
                <<<'SQL'
        IF checked_candidate.organization_id <> checked_event.organization_id
           OR checked_candidate.project_id IS DISTINCT FROM checked_event.project_id
           OR checked_candidate.purchase_request_id <> checked_event.purchase_request_id
           OR checked_candidate.supplier_request_id <> checked_event.supplier_request_id THEN
SQL,
                <<<'SQL'
        IF checked_candidate.organization_id <> checked_event.organization_id
           OR checked_candidate.project_id IS DISTINCT FROM checked_event.project_id
           OR checked_candidate.purchase_request_id <> checked_event.purchase_request_id
           OR (checked_event.selection_scope = 'supplier_request'
               AND checked_candidate.supplier_request_id <> checked_event.supplier_request_id) THEN
SQL,
            ],
            [
                <<<'SQL'
    IF NOT EXISTS (
        SELECT 1 FROM procurement_award_evidence_candidates candidate
        WHERE candidate.event_id = checked_event.id
SQL,
                <<<'SQL'
    IF checked_event.selection_scope = 'purchase_request'
       AND (
            SELECT count(DISTINCT candidate.supplier_request_id)
            FROM procurement_award_evidence_candidates candidate
            WHERE candidate.event_id = checked_event.id
       ) < 2 THEN
        RAISE EXCEPTION 'procurement award purchase request scope requires multiple supplier requests' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM procurement_award_evidence_candidates candidate
        WHERE candidate.event_id = checked_event.id
SQL,
            ],
            [
                <<<'SQL'
           OR predecessor.supplier_request_version_hash IS DISTINCT FROM checked_event.supplier_request_version_hash
           OR predecessor.selected_status <> checked_event.selected_status
SQL,
                <<<'SQL'
           OR predecessor.supplier_request_version_hash IS DISTINCT FROM checked_event.supplier_request_version_hash
           OR predecessor.selection_scope <> checked_event.selection_scope
           OR predecessor.selected_status <> checked_event.selected_status
SQL,
            ],
            [
                <<<'SQL'
    expected_hash := procurement_award_hash_parts(VARIADIC ARRAY[
        checked_event.organization_id::text,
SQL,
                <<<'SQL'
    expected_hash := procurement_award_hash_parts(VARIADIC (ARRAY[
        checked_event.organization_id::text,
SQL,
            ],
            [
                <<<'SQL'
        checked_event.predecessor_event_id::text,
        checked_event.purchase_order_id::text
    ]);
    IF checked_event.source_hash <> expected_hash THEN
SQL,
                <<<'SQL'
        checked_event.predecessor_event_id::text,
        checked_event.purchase_order_id::text
    ] || CASE
        WHEN checked_event.selection_scope = 'purchase_request'
            THEN ARRAY[checked_event.selection_scope]
        ELSE ARRAY[]::text[]
    END));
    IF checked_event.source_hash <> expected_hash THEN
SQL,
            ],
        ];
    }

    /** @return array<int, array{string, string}> */
    private function downReplacements(): array
    {
        return array_map(
            static fn (array $replacement): array => [$replacement[1], $replacement[0]],
            array_reverse($this->upReplacements()),
        );
    }

    /** @param array<int, array{string, string}> $replacements */
    private function rewriteDeferredValidator(array $replacements): void
    {
        $definition = DB::scalar(
            "SELECT pg_get_functiondef('procurement_award_deferred_validate()'::regprocedure)"
        );
        if (! is_string($definition) || $definition === '') {
            throw new \RuntimeException('procurement_award_deferred_validator_missing');
        }

        foreach ($replacements as [$search, $replace]) {
            if (substr_count($definition, $search) !== 1) {
                throw new \RuntimeException('procurement_award_deferred_validator_contract_mismatch');
            }

            $definition = str_replace($search, $replace, $definition);
        }

        DB::unprepared($definition);
    }
};
