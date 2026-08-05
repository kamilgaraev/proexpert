<?php

declare(strict_types=1);

namespace Tests\Integration\Reporting\Waves23;

use App\Models\Contract;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Reporting\PostgresProcessRaceHarness;
use Tests\TestCase;

#[Group('postgres')]
final class HoldingPerformanceProjectionPostgresTest extends TestCase
{
    #[Test]
    public function immutable_contract_version_evidence_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_contract_version_evidence')->insertGetId([
            'allocation_history_id' => 991001,
            'contract_id' => 881001,
            'organization_id' => 771001,
            'total_amount' => '1234.56',
            'contractor_id' => null,
            'counterparty_organization_id' => null,
            'recorded_at' => '2026-07-30 10:00:00+00',
            'source_hash' => hash('sha256', 'immutable-contract-version'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_contract_version_evidence')
            ->where('id', $id)
            ->update(['total_amount' => '9999.99']);
    }

    #[Test]
    public function accepted_work_event_version_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_accepted_work_event_versions')->insertGetId([
            'event_key' => 'test-event-'.str()->uuid(),
            'performance_act_id' => 991002,
            'contract_id' => 881002,
            'project_id' => 771002,
            'organization_id' => 661002,
            'active' => true,
            'amount' => '100.00',
            'status' => 'approved',
            'occurred_at' => '2026-07-30 10:00:00+00',
            'recorded_at' => '2026-07-30 10:00:01+00',
            'source_hash' => hash('sha256', 'immutable-accepted-work-event'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_accepted_work_event_versions')
            ->where('id', $id)
            ->update(['active' => false]);
    }

    #[Test]
    public function payment_event_version_rejects_mutation(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $id = DB::table('holding_payment_transaction_event_versions')->insertGetId([
            'transaction_id' => 991005,
            'payment_document_id' => 881005,
            'organization_id' => 771005,
            'project_id' => 661005,
            'contract_id' => 551005,
            'document_organization_id' => 771005,
            'document_project_id' => 661005,
            'contract_organization_id' => 771005,
            'contract_project_id' => 661005,
            'amount' => '-125.50',
            'currency' => 'RUB',
            'status' => 'completed',
            'active' => true,
            'recognized_at' => '2026-08-05 10:00:00+00',
            'occurred_at' => '2026-08-05 10:01:00+00',
            'recorded_at' => '2026-08-05 10:01:01+00',
            'history_complete' => true,
            'source_hash' => hash('sha256', 'immutable-payment-event'),
        ]);

        $this->expectException(QueryException::class);
        DB::table('holding_payment_transaction_event_versions')
            ->where('id', $id)
            ->update(['amount' => '0.00']);
    }

    #[Test]
    public function payment_capture_fails_closed_on_scope_mismatch_and_closes_lost_eligibility(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        DB::beginTransaction();

        try {
            $organization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $project = Project::withoutEvents(
                static fn (): Project => Project::factory()->for($organization)->create(),
            );
            $foreignOrganization = Organization::withoutEvents(
                static fn (): Organization => Organization::factory()->create(),
            );
            $foreignProject = Project::withoutEvents(
                static fn (): Project => Project::factory()->for($foreignOrganization)->create(),
            );
            $contractor = Contractor::withoutEvents(static fn (): Contractor => Contractor::create([
                'organization_id' => $foreignOrganization->id,
                'name' => 'Payment lifecycle '.str()->uuid(),
                'contractor_type' => Contractor::TYPE_MANUAL,
            ]));
            $contract = Contract::withoutEvents(static fn (): Contract => Contract::create([
                'organization_id' => $foreignOrganization->id,
                'project_id' => $foreignProject->id,
                'contractor_id' => $contractor->id,
                'number' => 'PAY-LIFECYCLE-'.str()->uuid(),
                'date' => '2026-08-05',
                'total_amount' => '100.00',
                'status' => 'active',
            ]));
            $now = now();
            $documentId = DB::table('payment_documents')->insertGetId([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'document_type' => 'payment_order',
                'document_number' => 'PAY-DOC-'.str()->uuid(),
                'document_date' => '2026-08-05',
                'direction' => 'outgoing',
                'invoiceable_type' => Contract::class,
                'invoiceable_id' => $contract->id,
                'amount' => '100.00',
                'currency' => 'RUB',
                'vat_amount' => '0.00',
                'vat_rate' => '0.00',
                'amount_without_vat' => '100.00',
                'paid_amount' => '100.00',
                'remaining_amount' => '0.00',
                'status' => 'paid',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $transactionId = DB::table('payment_transactions')->insertGetId([
                'payment_document_id' => $documentId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'amount' => '100.00',
                'currency' => 'RUB',
                'payment_method' => 'bank_transfer',
                'reference_number' => 'PAY-TX-'.str()->uuid(),
                'transaction_date' => '2026-08-05',
                'value_date' => '2026-08-05',
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            self::assertFalse((bool) DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->value('history_complete'));

            DB::table('contracts')->where('id', $contract->id)->update([
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'updated_at' => $now->copy()->addSecond(),
            ]);

            $contractScopeEvents = DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->limit(2)
                ->get(['active', 'history_complete']);
            self::assertSame([true, false], $contractScopeEvents->pluck('active')->map(
                static fn (mixed $value): bool => (bool) $value,
            )->all());
            self::assertTrue((bool) $contractScopeEvents->first()->history_complete);

            $refundId = DB::table('payment_transactions')->insertGetId([
                'payment_document_id' => $documentId,
                'organization_id' => $organization->id,
                'project_id' => $project->id,
                'amount' => '-25.00',
                'currency' => 'RUB',
                'payment_method' => 'bank_transfer',
                'reference_number' => 'PAY-REFUND-'.str()->uuid(),
                'transaction_date' => '2026-08-05',
                'value_date' => '2026-08-05',
                'status' => 'completed',
                'created_at' => $now,
                'updated_at' => $now->copy()->addSeconds(2),
            ]);
            $latestIds = DB::table('holding_payment_transaction_event_versions')
                ->selectRaw('MAX(id)')
                ->whereIn('transaction_id', [$transactionId, $refundId])
                ->groupBy('transaction_id');
            self::assertSame('75.00', number_format((float) DB::table('holding_payment_transaction_event_versions')
                ->whereIn('id', $latestIds)
                ->where('active', true)
                ->where('history_complete', true)
                ->sum('amount'), 2, '.', ''));

            DB::table('payment_transactions')->where('id', $transactionId)->update([
                'project_id' => null,
                'updated_at' => $now->copy()->addSeconds(3),
            ]);

            $lostScopeEvents = DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $transactionId)
                ->latest('id')
                ->limit(2)
                ->get(['active', 'history_complete']);
            self::assertSame([true, false], $lostScopeEvents->pluck('active')->map(
                static fn (mixed $value): bool => (bool) $value,
            )->all());
            self::assertFalse((bool) $lostScopeEvents->first()->history_complete);

            DB::table('payment_documents')->where('id', $documentId)->update([
                'invoiceable_type' => null,
                'invoiceable_id' => null,
                'updated_at' => $now->copy()->addSeconds(4),
            ]);

            self::assertFalse((bool) DB::table('holding_payment_transaction_event_versions')
                ->where('transaction_id', $refundId)
                ->latest('id')
                ->value('active'));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function accepted_work_event_identity_converges_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-event-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-event-race-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1, 2] as $worker) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881003, 'organization_id' => 661003], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991003,
                        'contract_id' => 881003,
                        'project_id' => 771003,
                        'is_approved' => true,
                        'amount' => '100.00',
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    $record = \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                        $act,
                        true,
                        new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                        $eventKey,
                        true,
                    );

                    return ['id' => (int) $record->getKey()];
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            self::assertSame($harness->result(1)['id'], $harness->result(2)['id']);
            self::assertSame(
                1,
                DB::table('holding_accepted_work_event_versions')->where('event_key', $eventKey)->count(),
            );
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }

    #[Test]
    public function public_accepted_work_record_detects_conflicting_payload_under_process_race(): void
    {
        if (config('database.default') !== 'pgsql') {
            self::markTestSkipped('PostgreSQL integration only.');
        }

        $suffix = bin2hex(random_bytes(6));
        $eventKey = 'race-conflict-'.$suffix;
        $harness = new PostgresProcessRaceHarness(
            sys_get_temp_dir().DIRECTORY_SEPARATOR.'accepted-work-conflict-'.$suffix,
        );
        $children = [];

        try {
            foreach ([1 => '100.00', 2 => '200.00'] as $worker => $amount) {
                $children[] = $harness->spawn($worker, static function () use ($eventKey, $amount): array {
                    $contract = new \App\Models\Contract;
                    $contract->setRawAttributes(['id' => 881004, 'organization_id' => 661004], true);
                    $act = new \App\Models\ContractPerformanceAct;
                    $act->setRawAttributes([
                        'id' => 991004,
                        'contract_id' => 881004,
                        'project_id' => 771004,
                        'is_approved' => true,
                        'amount' => $amount,
                        'status' => \App\Models\ContractPerformanceAct::STATUS_APPROVED,
                    ], true);
                    $act->setRelation('contract', $contract);
                    try {
                        \App\BusinessModules\Core\MultiOrganization\Reporting\Models\HoldingAcceptedWorkEventVersion::record(
                            $act,
                            true,
                            new \DateTimeImmutable('2026-07-30T10:00:00+00:00'),
                            $eventKey,
                            true,
                        );

                        return ['created' => true, 'conflict' => false];
                    } catch (\InvalidArgumentException $exception) {
                        return ['created' => false, 'conflict' => $exception->getMessage() === 'accepted_work_event_conflict'];
                    }
                });
            }
            $harness->release(1);
            $harness->release(2);
            $harness->waitForChildren($children);
            $children = [];

            $results = [$harness->result(1), $harness->result(2)];
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['created'])));
            self::assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['conflict'])));
        } finally {
            $harness->terminateAndReap($children);
            $harness->cleanup();
        }
    }
}
