<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiUsagePrivacyContractTest extends TestCase
{
    #[Test]
    public function usage_dto_has_no_content_or_secret_fields_and_legacy_accounting_is_absent(): void
    {
        foreach ([AiUsageData::class, AiOperationContext::class, AiPriceSnapshot::class] as $class) {
            $properties = array_map(static fn ($property): string => strtolower($property->getName()), (new ReflectionClass($class))->getProperties());
            foreach (['prompt', 'messages', 'request', 'response', 'error_body', 'filename', 'path', 'secret', 'content', 'api_key', 'authorization'] as $forbidden) {
                self::assertNotContains($forbidden, $properties, $class);
            }
        }
        $root = dirname(__DIR__, 4);
        $reranker = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php');
        $ocr = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php');
        self::assertStringNotContainsString('UsageTracker', (string) $reranker.(string) $ocr);
        self::assertStringNotContainsString('OcrUsageLogger', (string) $reranker.(string) $ocr);
    }
}
