<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use App\Integrations\EstimateGeneration\EstimateLearningExampleExtractor;
use App\Models\Estimate;
use PHPUnit\Framework\TestCase;

final class EstimateLearningExampleExtractorTest extends TestCase
{
    public function test_ai_generated_estimate_returns_no_learning_examples(): void
    {
        $estimate = new Estimate([
            'metadata' => [
                'is_ai_generated' => true,
                'generation_session_id' => 100,
            ],
        ]);

        $extractor = new EstimateLearningExampleExtractor(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog)
        );

        $this->assertSame([], $extractor->extractFromImportedEstimate($estimate));
    }
}
