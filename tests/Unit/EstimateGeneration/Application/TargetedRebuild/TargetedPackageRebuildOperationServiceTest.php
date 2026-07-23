<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildJobScheduler;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationData;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationService;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationStoreResult;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuildOperationServiceTest extends TestCase
{
    #[Test]
    public function it_durably_schedules_one_eligible_published_operation_after_commit(): void
    {
        $store = new InMemoryTargetedPackageRebuildOperationStore;
        $scheduler = new RecordingTargetedPackageRebuildJobScheduler;
        $service = new TargetedPackageRebuildOperationService(
            $store,
            new TargetedPackageRebuildOperationFactory,
            $scheduler,
            true,
            static fn (): string => '018f809a-e85e-7382-b419-00f5a7d7ab59',
        );

        $first = $service->scheduleAfterPublishedDraft($this->session(), $this->draft());
        $second = $service->scheduleAfterPublishedDraft($this->session(), $this->draft());

        self::assertNotNull($first);
        self::assertSame($first, $second);
        self::assertSame(['018f809a-e85e-7382-b419-00f5a7d7ab59'], $scheduler->operationIds);
        self::assertCount(1, $store->operations);
        self::assertSame('queued', $first->status);
        self::assertSame('roof', $first->packageKey);
    }

    #[Test]
    public function it_does_not_create_or_schedule_an_operation_when_the_active_contour_is_disabled(): void
    {
        $store = new InMemoryTargetedPackageRebuildOperationStore;
        $scheduler = new RecordingTargetedPackageRebuildJobScheduler;
        $service = new TargetedPackageRebuildOperationService(
            $store,
            new TargetedPackageRebuildOperationFactory,
            $scheduler,
            false,
            static fn (): string => '018f809a-e85e-7382-b419-00f5a7d7ab59',
        );

        $operation = $service->scheduleAfterPublishedDraft($this->session(), $this->draft());

        self::assertNull($operation);
        self::assertSame([], $store->operations);
        self::assertSame([], $scheduler->operationIds);
    }

    private function session(): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 11,
            'organization_id' => 4,
            'project_id' => 7,
            'state_version' => 8,
            'status' => EstimateGenerationStatus::ReadyToApply,
        ]);

        return $session;
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return [
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'local_estimates' => [
                ['key' => 'roof', 'sections' => []],
                ['key' => 'walls', 'sections' => []],
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'targeted_rebuild',
                'input_hash' => 'sha256:'.str_repeat('b', 64),
                'findings' => [[
                    'action' => 'rebuild',
                    'package_keys' => ['roof'],
                    'evidence_refs' => ['evidence:roof'],
                ]],
                'cycle' => [
                    'input_hash' => 'sha256:'.str_repeat('b', 64),
                    'attempted' => false,
                    'target_package_keys' => ['roof'],
                    'status' => 'shadow_recommendation',
                    'terminal_outcome' => 'targeted_rebuild',
                ],
            ],
        ];
    }
}

final class InMemoryTargetedPackageRebuildOperationStore implements TargetedPackageRebuildOperationStore
{
    /** @var array<string, TargetedPackageRebuildOperationData> */
    public array $operations = [];

    public function createOrFind(TargetedPackageRebuildOperationData $operation): TargetedPackageRebuildOperationStoreResult
    {
        $existing = $this->operations[$operation->idempotencyKey] ?? null;
        if ($existing instanceof TargetedPackageRebuildOperationData) {
            return new TargetedPackageRebuildOperationStoreResult($existing, false);
        }

        $this->operations[$operation->idempotencyKey] = $operation;

        return new TargetedPackageRebuildOperationStoreResult($operation, true);
    }

    public function find(string $operationId): ?TargetedPackageRebuildOperationData
    {
        foreach ($this->operations as $operation) {
            if ($operation->operationId === $operationId) {
                return $operation;
            }
        }

        return null;
    }

    public function claimQueued(string $operationId, string $leaseToken, \DateTimeImmutable $leaseExpiresAt): ?TargetedPackageRebuildOperationData
    {
        $operation = $this->find($operationId);
        if (! $operation instanceof TargetedPackageRebuildOperationData || $operation->status !== 'queued') {
            return null;
        }

        $claimed = $operation->withLease($leaseToken, $leaseExpiresAt);
        $this->operations[$claimed->idempotencyKey] = $claimed;

        return $claimed;
    }

    public function save(TargetedPackageRebuildOperationData $operation): void
    {
        $this->operations[$operation->idempotencyKey] = $operation;
    }
}

final class RecordingTargetedPackageRebuildJobScheduler implements TargetedPackageRebuildJobScheduler
{
    /** @var list<string> */
    public array $operationIds = [];

    public function schedule(string $operationId): void
    {
        $this->operationIds[] = $operationId;
    }
}
