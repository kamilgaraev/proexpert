<?php

declare(strict_types=1);

namespace Tests\Feature\Procurement\Reporting\Award;

use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Reporting\Award\Enums\ProcurementAwardSelectionScope;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardEvidenceStore;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\EloquentProcurementAwardSelectionSource;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardEvidenceRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardManifestBuilder;
use App\BusinessModules\Features\Procurement\Reporting\Award\Services\ProcurementAwardOwnerEventRecorder;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\LaravelProcurementTransactionBoundary;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Procurement\Reporting\Award\ProcurementAwardPostgresFixture;
use Tests\TestCase;

// Regression: ISSUE-086 — выбор победителя по нескольким запросам поставщика падал на PostgreSQL-инварианте.
// Found by /qa on 2026-08-29
// Report: .gstack/qa-reports/qa-report-most-full-2026-08-28.md
#[Group('postgresql')]
final class ProcurementAwardPurchaseRequestScopeRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (getenv('PROCUREMENT_AWARD_POSTGRES_TESTS') !== '1') {
            $this->markTestSkipped('Set PROCUREMENT_AWARD_POSTGRES_TESTS=1 to run isolated PostgreSQL tests.');
        }

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires an explicitly configured isolated PostgreSQL database.');
        }

        $database = config('database.connections.pgsql.database');
        if (! is_string($database) || preg_match('/_(?:test|testing)$/D', $database) !== 1) {
            $this->markTestSkipped('PostgreSQL database name must end with _test or _testing.');
        }
    }

    public function test_purchase_request_selection_persists_candidates_from_multiple_supplier_requests(): void
    {
        $connection = DB::connection();
        if (! $connection instanceof Connection) {
            throw new RuntimeException('Procurement award regression requires a Laravel database connection.');
        }

        $fixture = (new ProcurementAwardPostgresFixture($connection))
            ->create('issue-086-'.bin2hex(random_bytes(5)));

        $selection = DB::transaction(function () use ($fixture) {
            $source = new EloquentProcurementAwardSelectionSource;
            $owner = new ProcurementAwardOwnerEventRecorder(
                new ProcurementAwardManifestBuilder,
                new ProcurementAwardEvidenceRecorder(
                    new EloquentProcurementAwardEvidenceStore,
                    new LaravelProcurementTransactionBoundary,
                ),
                $source,
            );
            $purchaseRequest = PurchaseRequest::query()->findOrFail($fixture['purchase_request_id']);
            $decision = SupplierProposalDecision::query()->findOrFail($fixture['decision_id']);
            $occurredAt = new DateTimeImmutable('2026-08-01T10:00:00.000000+00:00');
            $prepared = $owner->prepareForPurchaseRequest(
                $purchaseRequest,
                $fixture['first']['proposal_id'],
                $occurredAt,
            );
            self::assertSame(ProcurementAwardSelectionScope::PURCHASE_REQUEST, $prepared->selectionScope);

            $owner->selected(
                $prepared,
                $decision,
                $occurredAt,
                $fixture['user_id'],
                'Сравнение предложений по всей заявке на закупку',
            );
            self::assertSame('purchase_request', DB::table('procurement_award_evidence_events')
                ->where('decision_id', $fixture['decision_id'])
                ->value('selection_scope'));

            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            return (new EloquentProcurementAwardEvidenceStore)
                ->eventsForDecision($fixture['decision_id'])[0];
        });

        self::assertSame(3, $selection->manifest->candidateCount);
        self::assertCount(2, array_unique(array_map(
            static fn ($candidate): int => $candidate->supplierRequestId,
            $selection->manifest->candidates,
        )));
        self::assertSame(3, DB::table('procurement_award_evidence_candidates')
            ->where('event_id', $selection->eventId)
            ->count());
    }
}
