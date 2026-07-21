<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateNumberAllocator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\LaravelGeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateDraftPersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePresenter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationReviewItemService;
use App\Models\Estimate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GeneratedEstimateScopeMetadataTest extends TestCase
{
    #[Test]
    public function it_keeps_direct_costs_and_persists_the_scope_boundary_separately(): void
    {
        $writer = new CapturingScopeGeneratedEstimateWriter(
            new EstimateDraftPersistenceService(
                new EstimateGenerationFinalWorkItemGuard,
                new EstimateGenerationReviewItemService(new EstimateGenerationPackagePresenter),
            ),
            new ScopeGeneratedEstimateNumberAllocator,
        );
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'input_payload' => [],
        ]);
        $session->id = 42;
        $session->exists = true;
        $draft = [
            'completeness' => [
                'status' => 'confirmed_scope_only',
                'scopes' => [[
                    'key' => 'heating',
                    'title' => 'Secret description from a document',
                    'state' => 'unresolved',
                    'required_items' => ['heating.unit'],
                    'covered_items' => [],
                    'missing_items' => ['heating.unit'],
                    'evidence_refs' => ['evidence:1'],
                    'exclusion_reason' => null,
                ]],
            ],
            'arbiter_review' => [
                'mode' => 'shadow',
                'status' => 'reviewed',
                'outcome' => 'human_review',
                'input_hash' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                'prompt_version' => 'completeness-arbiter:v1',
                'schema_version' => 'completeness-arbiter:v1',
                'model' => 'openai/gpt-5-mini',
                'input_tokens' => 100,
                'output_tokens' => 20,
                'findings' => [[
                    'scope_key' => 'heating',
                    'package_keys' => ['heating'],
                    'evidence_refs' => ['evidence:1'],
                    'action' => 'review',
                    'reason_code' => 'evidence_required',
                    'raw_reason' => 'Secret document text',
                ]],
                'raw_prompt' => 'Secret description from a document',
            ],
        ];

        $writer->createEstimateForTest($session, $draft, 1200.0);

        self::assertSame(1200.0, $writer->attributes['total_direct_costs']);
        self::assertSame(1200.0, $writer->attributes['total_amount']);
        self::assertSame(1200.0, $writer->attributes['total_amount_with_vat']);
        self::assertSame([
            'status' => 'confirmed_scope_only',
            'scopes' => [[
                'key' => 'heating',
                'state' => 'unresolved',
                'required_items' => ['heating.unit'],
                'covered_items' => [],
                'missing_items' => ['heating.unit'],
                'evidence_refs' => ['evidence:1'],
                'exclusion_reason' => null,
            ]],
        ], $writer->attributes['metadata']['ai_scope']['completeness']);
        self::assertSame(1200.0, $writer->attributes['metadata']['ai_scope']['budget_scope']['direct_costs']);
        self::assertSame('not_calculated', $writer->attributes['metadata']['ai_scope']['budget_scope']['overhead']['status']);
        self::assertNull($writer->attributes['metadata']['ai_scope']['budget_scope']['commercial_budget']['amount']);
        self::assertSame([
            'mode' => 'shadow',
            'status' => 'reviewed',
            'outcome' => 'human_review',
            'input_hash' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'prompt_version' => 'completeness-arbiter:v1',
            'schema_version' => 'completeness-arbiter:v1',
            'model' => 'openai/gpt-5-mini',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'findings' => [[
                'scope_key' => 'heating',
                'package_keys' => ['heating'],
                'evidence_refs' => ['evidence:1'],
                'action' => 'review',
                'reason_code' => 'evidence_required',
            ]],
        ], $writer->attributes['metadata']['ai_scope']['arbiter_review']);
    }
}

final class ScopeGeneratedEstimateNumberAllocator implements GeneratedEstimateNumberAllocator
{
    public function allocate(EstimateGenerationSession $session, int $attempt): string
    {
        return 'AI-scope-'.$attempt;
    }
}

final class CapturingScopeGeneratedEstimateWriter extends LaravelGeneratedEstimateWriter
{
    /** @var array<string, mixed> */
    public array $attributes = [];

    public function createEstimateForTest(EstimateGenerationSession $session, array $draft, float $total): Estimate
    {
        return $this->createEstimate(
            $session,
            new ApplyGeneratedEstimateCommand(42, 10, 20, 0),
            $draft,
            [],
            $total,
        );
    }

    protected function transactionAttempt(callable $callback): mixed
    {
        return $callback();
    }

    protected function createEstimateAttempt(array $attributes): Estimate
    {
        $this->attributes = $attributes;

        return new Estimate;
    }
}
