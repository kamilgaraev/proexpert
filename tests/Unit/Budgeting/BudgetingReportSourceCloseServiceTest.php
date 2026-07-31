<?php

declare(strict_types=1);

namespace Tests\Unit\Budgeting;

use App\BusinessModules\Features\Budgeting\Contracts\BudgetingReportSourceCloseStore;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceCloseIdentity;
use App\BusinessModules\Features\Budgeting\DTOs\BudgetingReportSourceWatermark;
use App\BusinessModules\Features\Budgeting\DTOs\CreateBudgetingReportSourceClose;
use App\BusinessModules\Features\Budgeting\Enums\BudgetingReportSourceCloseStatus;
use App\BusinessModules\Features\Budgeting\Services\BudgetingReportSourceCloseService;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class BudgetingReportSourceCloseServiceTest extends TestCase
{
    public function test_content_hash_is_canonical_across_manifest_and_watermark_order(): void
    {
        $identity = $this->identity();
        $first = $this->watermarks();
        $second = array_reverse($first);

        $firstHash = CreateBudgetingReportSourceClose::contentHashFor($identity, $first, 'margin-v1', [
            'sources' => ['budget' => ['version' => 'v2'], 'actual' => ['cutoff' => '2026-01-31']],
        ]);
        $secondHash = CreateBudgetingReportSourceClose::contentHashFor($identity, $second, 'margin-v1', [
            'sources' => ['actual' => ['cutoff' => '2026-01-31'], 'budget' => ['version' => 'v2']],
        ]);

        self::assertSame($firstHash, $secondHash);
    }

    public function test_close_rejects_a_hash_that_does_not_match_immutable_content(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CreateBudgetingReportSourceClose(
            closeId: '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            identity: $this->identity(),
            sourceWatermarks: $this->watermarks(),
            formulaVersion: 'margin-v1',
            sourceManifest: ['budget_version' => 'budget-v2'],
            contentHash: str_repeat('0', 64),
            approvedBy: 11,
            approvedAt: new DateTimeImmutable('2026-01-31T18:00:00+00:00'),
            retainedUntil: new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
        );
    }

    public function test_only_one_active_close_is_created_for_an_identity_without_restatement(): void
    {
        $store = new InMemoryBudgetingReportSourceCloseStore();
        $service = new BudgetingReportSourceCloseService($store);
        $first = $this->request('01JZZZZZZZZZZZZZZZZZZZZZZZ');

        $service->createApproved($first);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('budgeting_report_source_close_active_exists');
        $service->createApproved($this->request('01K00000000000000000000000'));
    }

    public function test_restatement_replaces_the_active_identity_only_when_it_names_the_prior_close(): void
    {
        $store = new InMemoryBudgetingReportSourceCloseStore();
        $service = new BudgetingReportSourceCloseService($store);
        $original = $service->createApproved($this->request('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

        $restated = $service->createApproved($this->request('01K00000000000000000000000', $original->closeId));

        self::assertSame($original->closeId, $restated->restatesCloseId);
        self::assertSame(BudgetingReportSourceCloseStatus::APPROVED, $restated->status);
        self::assertSame(BudgetingReportSourceCloseStatus::RESTATED, $store->find($original->closeId)?->status);
        self::assertTrue(BudgetingReportSourceCloseStatus::APPROVED->canTransitionTo(BudgetingReportSourceCloseStatus::RESTATED));
        self::assertFalse(BudgetingReportSourceCloseStatus::RESTATED->canTransitionTo(BudgetingReportSourceCloseStatus::APPROVED));
    }

    public function test_validated_close_requires_matching_identity_and_retention(): void
    {
        $store = new InMemoryBudgetingReportSourceCloseStore();
        $service = new BudgetingReportSourceCloseService($store);
        $close = $service->createApproved($this->request('01JZZZZZZZZZZZZZZZZZZZZZZZ'));

        self::assertSame($close, $service->validatedCloseForReporting(
            $close->closeId,
            $this->identity(),
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        ));

        $this->expectException(DomainException::class);
        $service->validatedCloseForReporting(
            $close->closeId,
            new BudgetingReportSourceCloseIdentity(7, '2026-01-01', '2026-01-31', 'base', 'budget-v3'),
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );
    }

    public function test_validated_close_rejects_an_expired_retention_deadline(): void
    {
        $store = new InMemoryBudgetingReportSourceCloseStore();
        $service = new BudgetingReportSourceCloseService($store);
        $close = $service->createApproved($this->request(
            '01JZZZZZZZZZZZZZZZZZZZZZZZ',
            retainedUntil: new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('budgeting_report_source_close_not_available');
        $service->validatedCloseForReporting(
            $close->closeId,
            $this->identity(),
            new DateTimeImmutable('2026-02-01T00:00:00+00:00'),
        );
    }

    private function identity(): BudgetingReportSourceCloseIdentity
    {
        return new BudgetingReportSourceCloseIdentity(7, '2026-01-01', '2026-01-31', 'base', 'budget-v2');
    }

    /** @return list<BudgetingReportSourceWatermark> */
    private function watermarks(): array
    {
        return [
            new BudgetingReportSourceWatermark('actuals', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'completed_work:771', 'actuals-v1'),
            new BudgetingReportSourceWatermark('budget', new DateTimeImmutable('2026-01-31T17:00:00+00:00'), 'budget_version:budget-v2', 'budget-v3'),
        ];
    }

    private function request(
        string $closeId,
        ?string $restatesCloseId = null,
        ?DateTimeImmutable $retainedUntil = null,
    ): CreateBudgetingReportSourceClose
    {
        $identity = $this->identity();
        $watermarks = $this->watermarks();
        $manifest = ['budget_version' => 'budget-v2', 'actuals_snapshot' => 'completed_work:771'];

        return new CreateBudgetingReportSourceClose(
            closeId: $closeId,
            identity: $identity,
            sourceWatermarks: $watermarks,
            formulaVersion: 'margin-v1',
            sourceManifest: $manifest,
            contentHash: CreateBudgetingReportSourceClose::contentHashFor($identity, $watermarks, 'margin-v1', $manifest),
            approvedBy: 11,
            approvedAt: new DateTimeImmutable('2026-01-31T18:00:00+00:00'),
            retainedUntil: $retainedUntil ?? new DateTimeImmutable('2033-01-31T00:00:00+00:00'),
            restatesCloseId: $restatesCloseId,
        );
    }
}

final class InMemoryBudgetingReportSourceCloseStore implements BudgetingReportSourceCloseStore
{
    /** @var array<string, BudgetingReportSourceClose> */
    private array $byId = [];

    /** @var array<string, string> */
    private array $activeByIdentity = [];

    public function createApproved(CreateBudgetingReportSourceClose $request): BudgetingReportSourceClose
    {
        $identity = json_encode($request->identity->toArray(), JSON_THROW_ON_ERROR);
        $activeCloseId = $this->activeByIdentity[$identity] ?? null;

        if ($request->restatesCloseId === null && $activeCloseId !== null) {
            throw new DomainException('budgeting_report_source_close_active_exists');
        }

        if ($request->restatesCloseId !== null && $activeCloseId !== $request->restatesCloseId) {
            throw new DomainException('budgeting_report_source_close_restatement_target_invalid');
        }

        if ($request->restatesCloseId !== null) {
            $prior = $this->byId[$request->restatesCloseId];
            $this->byId[$prior->closeId] = new BudgetingReportSourceClose(
                closeId: $prior->closeId,
                identity: $prior->identity,
                sourceWatermarks: $prior->sourceWatermarks,
                formulaVersion: $prior->formulaVersion,
                sourceManifest: $prior->sourceManifest,
                contentHash: $prior->contentHash,
                approvedBy: $prior->approvedBy,
                approvedAt: $prior->approvedAt,
                retainedUntil: $prior->retainedUntil,
                status: BudgetingReportSourceCloseStatus::RESTATED,
                restatesCloseId: $prior->restatesCloseId,
            );
        }

        $close = new BudgetingReportSourceClose(
            closeId: $request->closeId,
            identity: $request->identity,
            sourceWatermarks: $request->sourceWatermarks,
            formulaVersion: $request->formulaVersion,
            sourceManifest: $request->sourceManifest,
            contentHash: $request->contentHash,
            approvedBy: $request->approvedBy,
            approvedAt: $request->approvedAt,
            retainedUntil: $request->retainedUntil,
            status: BudgetingReportSourceCloseStatus::APPROVED,
            restatesCloseId: $request->restatesCloseId,
        );
        $this->byId[$close->closeId] = $close;
        $this->activeByIdentity[$identity] = $close->closeId;

        return $close;
    }

    public function find(string $closeId): ?BudgetingReportSourceClose
    {
        return $this->byId[$closeId] ?? null;
    }
}
