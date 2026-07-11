<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\DuplicatePipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineRegistryTest extends TestCase
{
    #[Test]
    public function processing_stages_have_the_only_valid_order(): void
    {
        self::assertSame([
            ProcessingStage::UnderstandDocuments,
            ProcessingStage::UnderstandObject,
            ProcessingStage::ExtractQuantities,
            ProcessingStage::PlanWorkItems,
            ProcessingStage::MatchNormatives,
            ProcessingStage::AssembleResources,
            ProcessingStage::ResolvePrices,
            ProcessingStage::BuildDraft,
            ProcessingStage::ValidateDraft,
        ], ProcessingStage::cases());

        self::assertSame(range(0, 8), array_map(
            static fn (ProcessingStage $stage): int => $stage->order(),
            ProcessingStage::cases(),
        ));
    }

    #[Test]
    public function registry_normalizes_arbitrary_registration_order(): void
    {
        $registry = new PipelineRegistry((static function (): iterable {
            yield new FakeStage(ProcessingStage::BuildDraft);
            yield new FakeStage(ProcessingStage::UnderstandObject);
            yield new FakeStage(ProcessingStage::ValidateDraft);
        })());

        self::assertSame([
            ProcessingStage::UnderstandObject,
            ProcessingStage::BuildDraft,
            ProcessingStage::ValidateDraft,
        ], array_map(
            static fn (PipelineStage $stage): ProcessingStage => $stage->stage(),
            $registry->ordered(),
        ));
    }

    #[Test]
    public function registry_rejects_duplicate_stage_registrations(): void
    {
        $this->expectException(DuplicatePipelineStage::class);

        new PipelineRegistry([
            new FakeStage(ProcessingStage::BuildDraft),
            new FakeStage(ProcessingStage::BuildDraft),
        ]);
    }

    #[Test]
    public function registry_returns_a_defensive_copy_and_supports_lookup(): void
    {
        $stage = new FakeStage(ProcessingStage::ExtractQuantities);
        $registry = new PipelineRegistry([$stage]);
        $ordered = $registry->ordered();
        $ordered[] = new FakeStage(ProcessingStage::ValidateDraft);

        self::assertSame([$stage], $registry->ordered());
        self::assertSame($stage, $registry->get(ProcessingStage::ExtractQuantities));
        self::assertNull($registry->get(ProcessingStage::ValidateDraft));
    }

    /**
     * @return iterable<string, array{int, int, int, int, string}>
     */
    public static function invalidContexts(): iterable
    {
        yield 'session ID must be positive' => [0, 2, 3, 0, 'sha256:input'];
        yield 'organization ID must be positive' => [1, -1, 3, 0, 'sha256:input'];
        yield 'project ID must be positive' => [1, 2, 0, 0, 'sha256:input'];
        yield 'state version cannot be negative' => [1, 2, 3, -1, 'sha256:input'];
        yield 'input version cannot be empty' => [1, 2, 3, 0, '   '];
        yield 'input version cannot contain controls' => [1, 2, 3, 0, "sha256:a\nunsafe"];
    }

    #[Test]
    #[DataProvider('invalidContexts')]
    public function context_rejects_invalid_identity_or_version_values(
        int $sessionId,
        int $organizationId,
        int $projectId,
        int $stateVersion,
        string $inputVersion,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new PipelineContext($sessionId, $organizationId, $projectId, $stateVersion, $inputVersion);
    }

    #[Test]
    public function context_preserves_strict_readonly_values(): void
    {
        $context = new PipelineContext(1, 2, 3, 0, 'sha256:input');

        self::assertSame(1, $context->sessionId);
        self::assertSame(2, $context->organizationId);
        self::assertSame(3, $context->projectId);
        self::assertSame(0, $context->stateVersion);
        self::assertSame('sha256:input', $context->inputVersion);
    }

    #[Test]
    public function pipeline_versions_accept_eighty_unicode_characters(): void
    {
        $version = str_repeat('я', 80);

        self::assertSame($version, (new PipelineContext(1, 2, 3, 0, $version))->inputVersion);
        self::assertSame(
            $version,
            (new PipelineStageResult(ProcessingStage::BuildDraft, $version, [], []))->outputVersion,
        );
    }

    #[Test]
    public function pipeline_context_rejects_versions_longer_than_eighty_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineContext(1, 2, 3, 0, str_repeat('я', 81));
    }

    #[Test]
    public function stage_result_rejects_versions_longer_than_eighty_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(ProcessingStage::BuildDraft, str_repeat('a', 81), [], []);
    }

    #[Test]
    public function stage_result_rejects_empty_output_version(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(ProcessingStage::BuildDraft, ' ', [], []);
    }

    #[Test]
    public function stage_result_rejects_object_metric_payloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', ['payload' => ['value' => new \stdClass]], []);
    }

    #[Test]
    public function stage_result_rejects_non_string_warnings(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', [], [123]);
    }

    #[Test]
    public function stage_result_rejects_named_warning_maps(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(
            ProcessingStage::BuildDraft,
            'sha256:output',
            [],
            ['review' => 'manual_review_required'],
        );
    }

    #[Test]
    public function stage_result_preserves_typed_contract_values(): void
    {
        $result = new PipelineStageResult(
            ProcessingStage::BuildDraft,
            'sha256:output',
            ['duration_ms' => 12],
            ['manual_review_required'],
        );

        self::assertSame(ProcessingStage::BuildDraft, $result->stage);
        self::assertSame('sha256:output', $result->outputVersion);
        self::assertSame(['duration_ms' => 12], $result->metrics);
        self::assertSame(['manual_review_required'], $result->warnings);
    }

    #[Test]
    public function stage_result_breaks_external_scalar_references(): void
    {
        $duration = 12;
        $warning = 'manual_review_required';
        $metrics = ['duration_ms' => &$duration];
        $warnings = [&$warning];
        $result = new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', $metrics, $warnings);

        $duration = 99;
        $warning = 'changed';

        self::assertSame(['duration_ms' => 12], $result->metrics);
        self::assertSame(['manual_review_required'], $result->warnings);
    }

    #[Test]
    public function stage_result_breaks_nested_array_references(): void
    {
        $confidence = 0.75;
        $details = ['confidence' => &$confidence];
        $metrics = ['quality' => &$details];
        $result = new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', $metrics, []);

        $confidence = 0.1;
        $details['accepted'] = true;

        self::assertSame(['quality' => ['confidence' => 0.75]], $result->metrics);
    }

    #[Test]
    public function stage_result_is_unchanged_when_source_arrays_are_modified(): void
    {
        $metrics = ['duration_ms' => 12];
        $warnings = ['manual_review_required'];
        $result = new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', $metrics, $warnings);

        $metrics['duration_ms'] = 99;
        $warnings[] = 'changed';

        self::assertSame(['duration_ms' => 12], $result->metrics);
        self::assertSame(['manual_review_required'], $result->warnings);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidMetricMaps(): iterable
    {
        yield 'empty key' => [['' => 1]];
        yield 'whitespace key' => [['   ' => 1]];
        yield 'unsafe key' => [["duration\nms" => 1]];
        yield 'numeric key' => [[0 => 1]];
        yield 'non-finite float' => [['confidence' => INF]];
    }

    #[Test]
    #[DataProvider('invalidMetricMaps')]
    public function stage_result_rejects_invalid_metric_maps(array $metrics): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PipelineStageResult(ProcessingStage::BuildDraft, 'sha256:output', $metrics, []);
    }
}

final readonly class FakeStage implements PipelineStage
{
    public function __construct(private ProcessingStage $processingStage) {}

    public function stage(): ProcessingStage
    {
        return $this->processingStage;
    }

    public function execute(PipelineContext $context): PipelineStageResult
    {
        return new PipelineStageResult($this->processingStage, $context->inputVersion, [], []);
    }
}
