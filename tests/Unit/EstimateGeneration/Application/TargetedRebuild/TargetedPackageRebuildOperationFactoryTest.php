<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Application\TargetedRebuild;

use App\BusinessModules\Addons\EstimateGeneration\Application\TargetedRebuild\TargetedPackageRebuildOperationFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetedPackageRebuildOperationFactoryTest extends TestCase
{
    #[Test]
    public function it_creates_one_idempotent_operation_from_a_published_single_package_shadow_cycle(): void
    {
        $operation = (new TargetedPackageRebuildOperationFactory)->fromPublishedDraft(
            operationId: '018f809a-e85e-7382-b419-00f5a7d7ab59',
            organizationId: 4,
            projectId: 7,
            sessionId: 11,
            stateVersion: 8,
            sessionStatus: 'ready_to_apply',
            draft: $this->draft(),
        );

        self::assertNotNull($operation);
        self::assertSame('roof', $operation->packageKey);
        self::assertSame('sha256:'.str_repeat('a', 64), $operation->sourceInputVersion);
        self::assertSame('sha256:'.str_repeat('b', 64), $operation->rootInputHash);
        self::assertSame(hash('sha256', '11|8|sha256:'.str_repeat('a', 64).'|sha256:'.str_repeat('b', 64).'|roof'), $operation->idempotencyKey);
    }

    #[Test]
    #[DataProvider('ineligiblePublishedDrafts')]
    public function it_refuses_to_create_an_operation_when_the_published_state_is_not_safe(
        bool $enabled,
        string $status,
        array $draft,
    ): void {
        $operation = (new TargetedPackageRebuildOperationFactory)->fromPublishedDraft(
            operationId: '018f809a-e85e-7382-b419-00f5a7d7ab59',
            organizationId: 4,
            projectId: 7,
            sessionId: 11,
            stateVersion: 8,
            sessionStatus: $status,
            draft: $draft,
            active: $enabled,
        );

        self::assertNull($operation);
    }

    /** @return iterable<string, array{bool, string, array<string, mixed>}> */
    public static function ineligiblePublishedDrafts(): iterable
    {
        $draft = self::draftTemplate();
        yield 'disabled rollout' => [false, 'ready_to_apply', $draft];
        yield 'session already applied' => [true, 'applied', $draft];
        yield 'session cancelled' => [true, 'cancelled', $draft];

        $multiple = $draft;
        $multiple['arbiter_review']['cycle']['target_package_keys'] = ['roof', 'walls'];
        yield 'multiple packages' => [true, 'ready_to_apply', $multiple];

        $unconfirmed = $draft;
        $unconfirmed['arbiter_review']['findings'][0]['evidence_refs'] = [];
        yield 'unconfirmed evidence' => [true, 'ready_to_apply', $unconfirmed];
    }

    /** @return array<string, mixed> */
    private function draft(): array
    {
        return self::draftTemplate();
    }

    /** @return array<string, mixed> */
    private static function draftTemplate(): array
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
