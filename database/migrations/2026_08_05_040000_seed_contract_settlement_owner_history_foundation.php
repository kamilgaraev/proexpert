<?php

declare(strict_types=1);

use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerHistoryBackfillService;
use App\BusinessModules\Features\ContractManagement\Reporting\ContractSettlementOwnerTimestamp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    public function up(): void
    {
        $evidence = DB::transaction(function (): array {
            DB::statement(
                'LOCK TABLE contracts, contract_project_allocations, contract_performance_acts, '
                .'payment_documents, payment_transactions IN SHARE ROW EXCLUSIVE MODE',
            );
            DB::statement('LOCK TABLE organizations IN SHARE ROW EXCLUSIVE MODE');
            DB::statement(
                'ALTER TABLE contract_settlement_owner_versions '
                .'ALTER COLUMN occurred_at TYPE timestamptz(6) USING occurred_at::timestamptz(6)',
            );
            DB::statement(
                'ALTER TABLE contract_settlement_owner_history_checkpoints '
                .'ALTER COLUMN completed_at TYPE timestamptz(6) USING completed_at::timestamptz(6)',
            );

            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION most_seed_contract_settlement_owner_checkpoint_v1()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    checkpoint_at timestamptz(6) := clock_timestamp()::timestamptz(6);
    counts jsonb := jsonb_build_object(
        'contract', 0,
        'contract_allocation', 0,
        'contract_performance_act', 0,
        'payment_document', 0,
        'payment_transaction', 0
    );
BEGIN
    INSERT INTO contract_settlement_owner_history_checkpoints (
        organization_id,
        completed_at,
        owner_counts,
        source_hash,
        created_at,
        updated_at
    ) VALUES (
        NEW.id,
        checkpoint_at,
        counts,
        encode(sha256(convert_to(jsonb_build_object(
            'organization_id', NEW.id,
            'completed_at', checkpoint_at,
            'owners', jsonb_build_array()
        )::text, 'UTF8')), 'hex'),
        checkpoint_at,
        checkpoint_at
    )
    ON CONFLICT (organization_id) DO NOTHING;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS most_seed_contract_settlement_owner_checkpoint_v1 ON organizations;
CREATE TRIGGER most_seed_contract_settlement_owner_checkpoint_v1
AFTER INSERT ON organizations
FOR EACH ROW EXECUTE FUNCTION most_seed_contract_settlement_owner_checkpoint_v1();
SQL);

            $existingCheckpointCount = DB::table('contract_settlement_owner_history_checkpoints')->count();
            if ($existingCheckpointCount !== 0) {
                throw new RuntimeException('Contract settlement owner checkpoint already exists.');
            }

            $backfill = app(ContractSettlementOwnerHistoryBackfillService::class);
            $checkpointCount = 0;
            $ownerCounts = [
                'contract' => 0,
                'contract_allocation' => 0,
                'contract_performance_act' => 0,
                'payment_document' => 0,
                'payment_transaction' => 0,
            ];
            $checkpointIdentities = [];

            DB::table('organizations')
                ->select('id')
                ->orderBy('id')
                ->chunkById(100, function ($organizations) use (
                    $backfill,
                    &$checkpointCount,
                    &$ownerCounts,
                    &$checkpointIdentities,
                ): void {
                    foreach ($organizations as $organization) {
                        $checkpoint = $backfill->backfill((int) $organization->id);
                        $counts = is_array($checkpoint->owner_counts) ? $checkpoint->owner_counts : [];
                        $expectedVersionCount = 0;
                        foreach (array_keys($ownerCounts) as $type) {
                            $count = $counts[$type] ?? null;
                            if (! is_int($count) || $count < 0) {
                                throw new RuntimeException('Contract settlement owner count is invalid.');
                            }
                            $ownerCounts[$type] += $count;
                            $expectedVersionCount += $count;
                        }

                        $capturedVersionCount = DB::table('contract_settlement_owner_versions')
                            ->where('organization_id', (int) $organization->id)
                            ->where(
                                'occurred_at',
                                ContractSettlementOwnerTimestamp::database($checkpoint->completed_at),
                            )
                            ->count();
                        if ($capturedVersionCount !== $expectedVersionCount) {
                            throw new RuntimeException('Contract settlement owner checkpoint coverage mismatch.');
                        }
                        if (! is_string($checkpoint->source_hash) || strlen($checkpoint->source_hash) !== 64) {
                            throw new RuntimeException('Contract settlement owner checkpoint hash is invalid.');
                        }

                        $checkpointCount++;
                        $checkpointIdentities[] = (int) $organization->id.':'.$checkpoint->source_hash;
                    }
                });

            $organizationCount = DB::table('organizations')->count();
            if ($checkpointCount !== $organizationCount) {
                throw new RuntimeException('Contract settlement owner checkpoint organization coverage mismatch.');
            }
            if (DB::table('contract_settlement_owner_history_checkpoints')->count() !== $organizationCount) {
                throw new RuntimeException('Contract settlement owner checkpoint persistence mismatch.');
            }

            return [
                'checkpoint_count' => $checkpointCount,
                'owner_counts' => $ownerCounts,
                'content_hash' => hash('sha256', implode('|', $checkpointIdentities)),
            ];
        });

        Log::info('report_contract_settlement_owner_history_foundation_completed', $evidence);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Contract settlement owner history foundation is irreversible because its evidence is append-only.',
        );
    }
};
