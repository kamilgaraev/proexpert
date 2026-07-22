<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\CommitTargetedPackageRebuild;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageCommitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftWriter;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackagePatchResult;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageReviewUpdater;
use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftSummaryProjector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\SessionBaseInputVersionResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterRemediationCoordinator;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterVerdict;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CommitTargetedPackageRebuildTest extends TestCase
{
    #[Test]
    public function it_rejects_a_changed_state_version_before_the_package_writer_is_called(): void
    {
        $storage = new InMemoryTargetedPackageCommitStore($this->session(8));
        $writer = new RecordingTargetedPackageDraftWriter;
        $commit = $this->commit($storage, $writer);

        try {
            $commit->commit($this->command(), $this->patchResult(), $this->reviewedDraft('passed'));
            self::fail('A stale state must be rejected before the package writer.');
        } catch (StaleEstimateGenerationState) {
        }

        self::assertSame([], $writer->syncedPackageKeys);
    }

    #[Test]
    public function it_syncs_only_the_exact_existing_target_after_the_second_review_passes(): void
    {
        $storage = new InMemoryTargetedPackageCommitStore($this->session());
        $writer = new RecordingTargetedPackageDraftWriter;

        $result = $this->commit($storage, $writer)->commit(
            $this->command(),
            $this->patchResult(),
            $this->reviewedDraft('passed'),
        );

        self::assertSame(['heating'], $writer->syncedPackageKeys);
        self::assertFalse($result->replayed);
        self::assertSame('reviewed', $storage->session->draft_payload['arbiter_review']['remediation']['phase']);
        self::assertSame('passed', $storage->session->draft_payload['arbiter_review']['outcome']);
    }

    #[Test]
    public function it_records_human_review_without_writing_the_rebuilt_package(): void
    {
        $storage = new InMemoryTargetedPackageCommitStore($this->session());
        $writer = new RecordingTargetedPackageDraftWriter;

        $this->commit($storage, $writer)->commit(
            $this->command(),
            $this->patchResult(),
            $this->reviewedDraft('human_review'),
        );

        self::assertSame([], $writer->syncedPackageKeys);
        self::assertSame('human_review', $storage->session->draft_payload['arbiter_review']['outcome']);
    }

    #[Test]
    public function it_replays_only_the_same_completed_operation_id(): void
    {
        $storage = new InMemoryTargetedPackageCommitStore($this->session());
        $writer = new RecordingTargetedPackageDraftWriter;
        $commit = $this->commit($storage, $writer);

        $first = $commit->commit($this->command(), $this->patchResult(), $this->reviewedDraft('passed'));
        $replay = $commit->commit($this->command(), $this->patchResult(), $this->reviewedDraft('passed'));

        self::assertFalse($first->replayed);
        self::assertTrue($replay->replayed);
        self::assertSame(['heating'], $writer->syncedPackageKeys);
    }

    #[Test]
    public function it_rejects_a_reviewed_draft_that_changes_a_package_after_the_patch(): void
    {
        $reviewed = $this->reviewedDraft('passed');
        $reviewed['local_estimates'][1]['sections'][0]['work_items'][0]['total_cost'] = 9999.0;

        $this->expectException(\DomainException::class);
        $this->commit(new InMemoryTargetedPackageCommitStore($this->session()), new RecordingTargetedPackageDraftWriter)
            ->commit($this->command(), $this->patchResult(), $reviewed);
    }

    #[Test]
    public function it_rejects_a_reviewed_draft_that_changes_a_non_target_package(): void
    {
        $reviewed = $this->reviewedDraft('passed');
        $reviewed['local_estimates'][0]['sections'][0]['work_items'][0]['total_cost'] = 9999.0;

        $this->expectException(\DomainException::class);
        $this->commit(new InMemoryTargetedPackageCommitStore($this->session()), new RecordingTargetedPackageDraftWriter)
            ->commit($this->command(), $this->patchResult(), $reviewed);
    }

    #[Test]
    public function it_rejects_a_patch_with_an_altered_non_target_fingerprint(): void
    {
        $patch = $this->patchResult();
        $altered = new TargetedPackagePatchResult(
            $patch->draft,
            $patch->packageKey,
            $patch->targetBeforeFingerprint,
            $patch->targetAfterFingerprint,
            ['foundation' => 'sha256:'.str_repeat('c', 64)],
        );

        $this->expectException(\DomainException::class);
        $this->commit(new InMemoryTargetedPackageCommitStore($this->session()), new RecordingTargetedPackageDraftWriter)
            ->commit($this->command(), $altered, $this->reviewedDraft('passed'));
    }

    #[Test]
    public function it_rejects_a_patch_that_rewrites_a_non_target_with_a_matching_new_fingerprint(): void
    {
        $patch = $this->patchResult();
        $alteredDraft = $patch->draft;
        $alteredDraft['local_estimates'][0]['sections'][0]['work_items'][0]['total_cost'] = 9999.0;
        $altered = new TargetedPackagePatchResult(
            $alteredDraft,
            $patch->packageKey,
            $patch->targetBeforeFingerprint,
            $patch->targetAfterFingerprint,
            [
                'foundation' => 'sha256:'.hash(
                    'sha256',
                    CanonicalPipelineJson::encode($alteredDraft['local_estimates'][0]),
                ),
            ],
        );
        $reviewed = (new ArbiterRemediationCoordinator)->resolveAfterRebuild(
            $alteredDraft,
            new ArbiterVerdict('passed', []),
        );

        $this->expectException(\DomainException::class);
        $this->commit(new InMemoryTargetedPackageCommitStore($this->session()), new RecordingTargetedPackageDraftWriter)
            ->commit($this->command(), $altered, $reviewed);
    }

    #[Test]
    public function it_rejects_a_replayed_operation_id_with_different_review_content(): void
    {
        $storage = new InMemoryTargetedPackageCommitStore($this->session());
        $commit = $this->commit($storage, new RecordingTargetedPackageDraftWriter);
        $commit->commit($this->command(), $this->patchResult(), $this->reviewedDraft('passed'));

        $this->expectException(\InvalidArgumentException::class);
        $commit->commit($this->command(), $this->patchResult(), $this->reviewedDraft('confirmed_scope_only'));
    }

    #[Test]
    public function it_rejects_a_second_review_with_an_unrelated_root_input_hash(): void
    {
        $reviewed = $this->reviewedDraft('passed');
        $reviewed['arbiter_review']['input_hash'] = 'sha256:'.str_repeat('d', 64);

        $this->expectException(\DomainException::class);
        $this->commit(new InMemoryTargetedPackageCommitStore($this->session()), new RecordingTargetedPackageDraftWriter)
            ->commit($this->command(), $this->patchResult(), $reviewed);
    }

    /** @return array<string, mixed> */
    private function initialDraft(): array
    {
        $inputHash = 'sha256:'.str_repeat('b', 64);

        return [
            'source_input_version' => 'sha256:'.str_repeat('a', 64),
            'local_estimates' => [
                $this->package('foundation', 'foundation.work', 1200.0),
                $this->package('heating', 'heating.work', 3000.0),
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'input_hash' => $inputHash,
                'outcome' => 'targeted_rebuild',
                'findings' => [[
                    'action' => 'rebuild',
                    'package_keys' => ['heating'],
                    'evidence_refs' => ['document:1'],
                ]],
                'cycle' => [
                    'input_hash' => $inputHash,
                    'attempted' => false,
                    'target_package_keys' => ['heating'],
                    'status' => 'shadow_recommendation',
                    'terminal_outcome' => 'targeted_rebuild',
                ],
            ],
        ];
    }

    private function command(): TargetedPackageRebuildCommand
    {
        $draft = (new ArbiterRemediationCoordinator)->markAttempted(
            $this->initialDraft(),
            'sha256:'.str_repeat('b', 64),
        );

        return new TargetedPackageRebuildCommand(
            sessionId: 41,
            organizationId: 7,
            projectId: 11,
            expectedStateVersion: 7,
            sourceInputVersion: 'sha256:'.str_repeat('a', 64),
            operationId: '11111111-1111-4111-8111-111111111111',
            arbiterInputHash: 'sha256:'.str_repeat('b', 64),
            packageKey: 'heating',
            verdict: new ArbiterVerdict('targeted_rebuild', [[
                'action' => 'rebuild',
                'package_keys' => ['heating'],
                'evidence_refs' => ['document:1'],
            ]]),
            sessionStatus: EstimateGenerationStatus::EstimateReviewRequired->value,
            draft: $draft,
        );
    }

    private function patchResult(): TargetedPackagePatchResult
    {
        $command = $this->command();
        $replacement = $this->package('heating', 'heating.work', 4200.0);

        return (new \App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageDraftPatcher)
            ->replace($command->draft, $command->sourceInputVersion, 'heating', $replacement);
    }

    /** @return array<string, mixed> */
    private function reviewedDraft(string $outcome): array
    {
        return (new ArbiterRemediationCoordinator)->resolveAfterRebuild(
            $this->patchResult()->draft,
            new ArbiterVerdict($outcome, []),
        );
    }

    private function session(int $stateVersion = 7): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'organization_id' => 7,
            'project_id' => 11,
            'state_version' => $stateVersion,
            'status' => EstimateGenerationStatus::EstimateReviewRequired,
            'draft_payload' => $this->initialDraft(),
            'applied_estimate_id' => null,
        ]);
        $session->forceFill(['id' => 41]);

        return $session;
    }

    private function commit(
        TargetedPackageCommitStore $storage,
        TargetedPackageDraftWriter $writer,
    ): CommitTargetedPackageRebuild {
        return new CommitTargetedPackageRebuild(
            $storage,
            $writer,
            new TargetedPackageDraftSummaryProjector,
            new ArbiterRemediationCoordinator,
            new FixedTargetedPackageInputVersionResolver('sha256:'.str_repeat('a', 64)),
            new InMemoryTargetedPackageReviewUpdater,
        );
    }

    /** @return array<string, mixed> */
    private function package(string $key, string $workItemKey, float $totalCost): array
    {
        return [
            'key' => $key,
            'title' => ucfirst($key),
            'sections' => [[
                'key' => $key.'.section',
                'title' => ucfirst($key),
                'work_items' => [[
                    'key' => $workItemKey,
                    'item_type' => 'priced_work',
                    'name' => ucfirst($key).' work',
                    'total_cost' => $totalCost,
                    'pricing_status' => 'calculated',
                    'normative_match' => [
                        'status' => 'matched',
                        'decision' => ['status' => 'accepted'],
                    ],
                    'validation_flags' => [],
                    'metadata' => ['composition_work_key' => $workItemKey],
                ]],
            ]],
        ];
    }
}

final class InMemoryTargetedPackageCommitStore implements TargetedPackageCommitStore
{
    /** @var array<string, array<string, mixed>> */
    public array $operations = [];

    public function __construct(public EstimateGenerationSession $session) {}

    public function withinLockedSession(int $sessionId, int $organizationId, int $projectId, \Closure $callback): mixed
    {
        if ((int) $this->session->id !== $sessionId
            || (int) $this->session->organization_id !== $organizationId
            || (int) $this->session->project_id !== $projectId) {
            throw new StaleEstimateGenerationState($sessionId, 0);
        }

        return $callback($this->session);
    }

    public function operation(EstimateGenerationSession $session, string $operationId): ?array
    {
        return $this->operations[$operationId] ?? null;
    }

    public function recordOperation(EstimateGenerationSession $session, array $payload): void
    {
        $this->operations[$payload['operation_id']] = $payload;
    }
}

final class RecordingTargetedPackageDraftWriter implements TargetedPackageDraftWriter
{
    /** @var list<string> */
    public array $syncedPackageKeys = [];

    public function syncPackageFromDraft(
        EstimateGenerationSession $session,
        string $packageKey,
        array $localEstimate,
        string $sourceInputVersion,
    ): void {
        $this->syncedPackageKeys[] = $packageKey;
    }
}

final class FixedTargetedPackageInputVersionResolver implements SessionBaseInputVersionResolver
{
    public function __construct(private readonly string $version) {}

    public function resolve(EstimateGenerationSession $session): string
    {
        return $this->version;
    }
}

final class InMemoryTargetedPackageReviewUpdater implements TargetedPackageReviewUpdater
{
    public function reviewUpdated(EstimateGenerationSession $session, bool $requiresReview, array $attributes): EstimateGenerationSession
    {
        $session->forceFill([
            ...$attributes,
            'status' => $requiresReview
                ? EstimateGenerationStatus::EstimateReviewRequired
                : EstimateGenerationStatus::ReadyToApply,
            'state_version' => (int) $session->state_version + 1,
        ]);

        return $session;
    }
}
