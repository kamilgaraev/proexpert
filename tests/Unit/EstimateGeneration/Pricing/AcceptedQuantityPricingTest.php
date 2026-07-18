<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pricing;

use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceMaterializer;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\AcceptedQuantityEvidenceVerifier;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\ResolveRegionalPrice;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimatePricingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AcceptedQuantityPricingTest extends TestCase
{
    #[Test]
    public function pricing_is_finalized_only_for_exact_accepted_quantity_identity(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new PipelineContext(
            30, 10, 20, 1, 'sha256:'.str_repeat('a', 64), 'generating',
            baseInputVersion: 'sha256:'.str_repeat('b', 64),
        );
        $quantity = QuantityData::fromArray([
            'key' => 'floor_area', 'unit' => 'm2', 'amount' => '12.000000',
            'formula_key' => 'floor.net_area', 'formula_version' => 'v1', 'formula_inputs' => [],
            'source' => 'evidenced', 'evidence_ids' => ['1'], 'model_version' => 'building-model:v1',
            'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = [
            'key' => 'floor-finish', 'item_type' => 'priced_work', 'quantity' => '12.000000', 'unit' => 'm2',
            'materials' => [['price_id' => 42, 'unit' => 'm2', 'quantity' => '12', 'normative_ref' => ['price_id' => 42, 'norm_resource_id' => 7]]],
            'labor' => [], 'machinery' => [], 'other_resources' => [],
        ];
        $node = (new AcceptedQuantityEvidenceMaterializer($evidence))->materialize($context, $quantity, $item);
        $item['quantity_evidence_id'] = $node->id;
        $item['quantity_evidence_fingerprint'] = $node->fingerprint;
        $pricing = new EstimatePricingService(new ResolveRegionalPrice(static fn (int $id): array => [
            'id' => $id, 'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'regional_price_version_id' => 11, 'base_price' => '10.0000', 'source_type' => 'fgiscs',
        ]), acceptedEvidence: new AcceptedQuantityEvidenceVerifier($evidence));
        $regional = ['region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8, 'estimate_regional_price_version_id' => 11];

        $accepted = $pricing->price([$item], $regional, $context)[0];
        $rejected = $pricing->price([array_replace($item, ['quantity_evidence_fingerprint' => str_repeat('c', 64)])], $regional, $context)[0];

        self::assertSame($accepted['price_snapshot']['captured_at'], $accepted['pricing_finalized_at']);
        self::assertSame('quantity_evidence_not_accepted', $rejected['pricing_blocker']);
        self::assertNull($rejected['pricing_finalized_at']);
        self::assertNull($rejected['price_snapshot']);
    }

    #[Test]
    public function stale_self_consistent_draft_evidence_is_rejected_against_current_persistence_version(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $stale = new PipelineContext(
            30, 10, 20, 1, 'sha256:'.str_repeat('a', 64), 'generating',
            baseInputVersion: 'sha256:'.str_repeat('b', 64),
        );
        $quantity = QuantityData::fromArray([
            'key' => 'floor_area', 'unit' => 'm2', 'amount' => '12.000000',
            'formula_key' => 'floor.net_area', 'formula_version' => 'v1', 'formula_inputs' => [],
            'source' => 'evidenced', 'evidence_ids' => ['1'], 'model_version' => 'building-model:v1',
            'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = ['key' => 'floor-finish', 'quantity' => '12.000000', 'unit' => 'm2'];
        $node = (new AcceptedQuantityEvidenceMaterializer($evidence))->materialize($stale, $quantity, $item);
        $item['quantity_evidence_id'] = $node->id;
        $item['quantity_evidence_fingerprint'] = $node->fingerprint;
        $item['quantity_evidence_source_version'] = (string) $stale->baseInputVersion;
        $currentVersion = 'sha256:'.str_repeat('c', 64);

        self::assertFalse((new AcceptedQuantityEvidenceVerifier($evidence))->verifyScope(10, 20, 30, $currentVersion, $item));
        self::assertSame(
            'source_version_mismatch',
            (new AcceptedQuantityEvidenceVerifier($evidence))->rejectionReason(10, 20, 30, $currentVersion, $item),
        );
        $persistence = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationPackagePersistenceService.php');
        self::assertStringNotContainsString("['quantity_evidence_source_version']", $persistence);
        self::assertStringContainsString('$package->input_version', $persistence);
    }

    #[Test]
    public function canonical_database_identifier_string_survives_pipeline_json_boundary(): void
    {
        $evidence = new InMemoryEvidenceRepository;
        $context = new PipelineContext(
            30, 10, 20, 1, 'sha256:'.str_repeat('a', 64), 'generating',
            baseInputVersion: 'sha256:'.str_repeat('b', 64),
        );
        $quantity = QuantityData::fromArray([
            'key' => 'floor_area', 'unit' => 'm2', 'amount' => '12.000000',
            'formula_key' => 'floor.net_area', 'formula_version' => 'v1', 'formula_inputs' => [],
            'source' => 'evidenced', 'evidence_ids' => ['1'], 'model_version' => 'building-model:v1',
            'assumptions' => [], 'review_blockers' => [],
        ]);
        $item = ['key' => 'floor-finish', 'quantity' => '12.000000', 'unit' => 'm2'];
        $node = (new AcceptedQuantityEvidenceMaterializer($evidence))->materialize($context, $quantity, $item);
        $item['quantity_evidence_id'] = (string) $node->id;
        $item['quantity_evidence_fingerprint'] = $node->fingerprint;

        self::assertTrue((new AcceptedQuantityEvidenceVerifier($evidence))->verify($context, $item));
    }
}
