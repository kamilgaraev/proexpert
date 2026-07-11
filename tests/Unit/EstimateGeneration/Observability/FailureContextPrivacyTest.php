<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailureContextPrivacyTest extends TestCase
{
    #[Test]
    #[DataProvider('unsafeProviderModel')]
    public function provider_and_model_reject_paths_tokens_and_unbounded_values(?string $provider, ?string $model): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FailureContext(1, 2, 3, ProcessingStage::BuildDraft, 'run_stage', 1,
            '018f4a20-3f4c-7a11-8a22-123456789abc', '018f4a20-3f4c-7a11-8a22-123456789abd',
            provider: $provider, model: $model);
    }

    public static function unsafeProviderModel(): iterable
    {
        yield 'provider token' => ['api_token', null];
        yield 'provider path' => ['vendor/path', null];
        yield 'model secret' => [null, 'secret-model'];
        yield 'model windows path' => [null, 'C:\\models\\private'];
        yield 'model too long' => [null, str_repeat('a', 81)];
    }
}
