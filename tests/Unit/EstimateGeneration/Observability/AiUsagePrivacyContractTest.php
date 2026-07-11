<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AiUsagePrivacyContractTest extends TestCase
{
    #[Test]
    public function usage_dto_has_no_content_or_secret_fields_and_legacy_accounting_is_absent(): void
    {
        $properties = array_map(static fn ($property): string => strtolower($property->getName()), (new ReflectionClass(AiUsageData::class))->getProperties());
        foreach (['prompt', 'messages', 'request', 'response', 'error_body', 'filename', 'path', 'secret', 'content', 'api_key', 'authorization'] as $forbidden) {
            self::assertNotContains($forbidden, $properties);
        }
        $root = dirname(__DIR__, 4);
        $reranker = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/Normatives/Reranking/LLMNormativeCandidateReranker.php');
        $ocr = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/Ocr/OcrDocumentProcessor.php');
        self::assertStringNotContainsString('UsageTracker', (string) $reranker.(string) $ocr);
        self::assertStringNotContainsString('OcrUsageLogger', (string) $reranker.(string) $ocr);
    }
}
