<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Analysis;

use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunInput;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\DTO\AiRoleRunResult;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiAnalysisRole;
use App\BusinessModules\Addons\EstimateGeneration\Analysis\Role\AiRoleRunStatus;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiRoleRunContractTest extends TestCase
{
    #[Test]
    public function batch_pipeline_exposes_exactly_eight_roles(): void
    {
        self::assertSame([
            'observer_literal',
            'observer_construction',
            'observer_risk',
            'arbiter',
            'geometry_expert',
            'project_engineer',
            'estimate_composer',
            'estimate_auditor',
        ], array_column(AiAnalysisRole::cases(), 'value'));
    }

    #[Test]
    public function role_run_statuses_separate_recoverable_failure_from_ambiguous_outcome(): void
    {
        self::assertSame([
            'running',
            'completed',
            'failed',
            'ambiguous',
        ], array_column(AiRoleRunStatus::cases(), 'value'));
    }

    #[Test]
    public function input_identity_changes_for_a_replaced_source_version(): void
    {
        $first = $this->input('sha256:'.str_repeat('a', 64));
        $replaced = $this->input('sha256:'.str_repeat('b', 64));

        self::assertNotSame($first->identityFingerprint(), $replaced->identityFingerprint());
        self::assertSame($first->identityFingerprint(), $this->input('sha256:'.str_repeat('a', 64))->identityFingerprint());
    }

    #[Test]
    public function input_rejects_a_fingerprint_that_is_not_lowercase_sha256(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiRoleRunInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            documentId: 40,
            pageId: 50,
            subjectType: 'document_page',
            subjectId: '50',
            subjectVersion: 'sha256:'.str_repeat('a', 64),
            role: AiAnalysisRole::LiteralObserver,
            model: 'pinned-multimodal-model',
            promptContractVersion: 'observer-literal:v1',
            inputFingerprint: str_repeat('A', 64),
        );
    }

    #[Test]
    public function result_rejects_payload_larger_than_the_bounded_contract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiRoleRunResult(
            payload: ['observation' => str_repeat('x', AiRoleRunResult::MAX_PAYLOAD_BYTES + 1)],
            physicalAttemptId: '11111111-1111-4111-8111-111111111111',
        );
    }

    private function input(string $sourceVersion): AiRoleRunInput
    {
        return new AiRoleRunInput(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            documentId: 40,
            pageId: 50,
            subjectType: 'document_page',
            subjectId: '50',
            subjectVersion: $sourceVersion,
            role: AiAnalysisRole::LiteralObserver,
            model: 'pinned-multimodal-model',
            promptContractVersion: 'observer-literal:v1',
            inputFingerprint: hash('sha256', 'render|'.$sourceVersion),
        );
    }
}
