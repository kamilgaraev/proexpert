<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\TimewebVisionOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\FixedOcrRuntimeEnvironment;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TimewebVisionUsageAttemptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $app->instance('log', new class
        {
            public function warning(string $message, array $context = []): void {}

            public function error(string $message, array $context = []): void {}
        });
        Facade::setFacadeApplication($app);
        Http::swap(new Factory);
        $app->instance(\App\BusinessModules\Addons\EstimateGeneration\Vision\PhysicalAttempt\VisionPhysicalAttemptStore::class,
            new \Tests\Support\EstimateGeneration\InMemoryVisionPhysicalAttemptStore);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function production_runtime_without_effective_snapshot_fails_closed(): void
    {
        $this->configure(['openai/gpt-6-luna'], 1);
        $this->expectException(OcrConfigurationException::class);
        (new TimewebVisionOcrClient(runtimeEnvironment: new FixedOcrRuntimeEnvironment(true)))->recognize($this->input());
    }

    #[Test]
    public function internal_retry_records_http_failure_then_success_exactly_once_each(): void
    {
        $store = $this->store();
        $this->configure(['openai/gpt-6-luna'], 2);
        Http::fakeSequence()
            ->push(['error' => ['code' => 'temporary'], 'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0]], 500)
            ->push($this->successPayload('openai/gpt-6-luna'), 200);

        ($this->client($store))->recognize($this->input());

        self::assertSame(['http_failed', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
        self::assertSame(['measured', 'measured'], array_map(fn (AiUsageData $row): string => $row->usageStatus, $store->rows));
    }

    #[Test]
    public function arbitrary_non_json_response_fails_without_changing_the_pinned_model(): void
    {
        $store = $this->store();
        $this->configure(['openai/gpt-6-luna'], 1);
        Http::fake(fn () => Http::response(['model' => 'openai/gpt-6-luna', 'choices' => [['message' => ['content' => 'plain OCR text']]]], 200));
        try {
            ($this->client($store))->recognize($this->input());
            self::fail('Malformed response must fail.');
        } catch (OcrProviderException) {
        }
        self::assertSame(['malformed_response'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
    }

    #[Test]
    public function durable_replay_does_not_send_or_record_twice_and_accounting_failure_stops_publication(): void
    {
        $store = $this->store();
        $this->configure(['openai/gpt-6-luna'], 1);
        Http::fake(fn () => Http::response($this->successPayload('openai/gpt-6-luna'), 200));
        $client = $this->client($store);

        $client->recognize($this->input());
        $client->recognize($this->input());
        $client->recognize($this->input('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7e'));

        self::assertCount(2, $store->rows);
        self::assertNotSame($store->rows[0]->context->attemptId, $store->rows[1]->context->attemptId);
        Http::assertSentCount(2);

        $throwing = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void
            {
                throw new \RuntimeException('recorder failed');
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recorder failed');
        $this->client($throwing)->recognize($this->input('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7f'));
    }

    #[Test]
    public function reported_model_mismatch_fails_without_changing_the_pinned_model(): void
    {
        $store = $this->store();
        $this->configure(['openai/gpt-6-luna'], 1);
        Http::fake(fn () => Http::response($this->successPayload('unexpected-model'), 200));
        try {
            ($this->client($store))->recognize($this->input());
            self::fail('Reported model mismatch must fail.');
        } catch (OcrProviderException) {
        }
        self::assertSame(['malformed_response'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
    }

    #[Test]
    public function unknown_connection_outcome_is_not_retried_and_terminal_http_has_one_attempt(): void
    {
        $store = $this->store();
        $this->configure(['openai/gpt-6-luna'], 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('offline');
            }

            return Http::response($this->successPayload('openai/gpt-6-luna'), 200);
        });
        try {
            $this->client($store)->recognize($this->input());
            self::fail('An unknown transport outcome must stop automatic sends.');
        } catch (OcrProviderException) {
        }
        self::assertSame(1, $calls);
        self::assertSame(['ambiguous'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));

        $terminalStore = $this->store();
        Http::swap(new Factory);
        $this->configure(['openai/gpt-6-luna', 'model-b'], 3);
        Http::fake(fn () => Http::response(['error' => ['code' => 'unauthorized']], 401));
        try {
            $this->client($terminalStore)->recognize($this->input('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7f'));
            self::fail('401 must stop retries and model routing.');
        } catch (OcrProviderException) {
        }
        self::assertCount(1, $terminalStore->rows);
        self::assertSame(401, $terminalStore->rows[0]->httpCode);
    }

    private function configure(array $models, int $retries): void
    {
        config()->set('estimate-generation.ocr.timeweb.api_key', 'fixture-key');
        config()->set('estimate-generation.ocr.timeweb.base_uri', 'https://fixture.invalid/v1');
        config()->set('estimate-generation.ocr.model', (string) ($models[0] ?? ''));
        config()->set('estimate-generation.ocr.retry_attempts', $retries);
        config()->set('estimate-generation.ocr.retry_delay_ms', 0);
    }

    private function client(AiUsageStore $store): TimewebVisionOcrClient
    {
        $prices = new class implements \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshotResolver
        {
            public function resolve(AiOperationContext $context, string $provider, string $model): \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot
            {
                return \App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot::fromArray([
                    'input_per_million' => '13.5', 'cached_input_per_million' => '13.5', 'output_per_million' => '67.5',
                    'reasoning_per_million' => '67.5', 'reasoning_mode' => 'included_in_output', 'image_unit' => '0', 'page_unit' => '0',
                    'currency' => 'RUB', 'source' => 'fixture', 'version' => 'luna.v1', 'effective_at' => '2026-10-01T00:00:00+00:00']);
            }
        };

        return new TimewebVisionOcrClient($store, priceResolver: $prices);
    }

    private function input(string $correlationId = '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'): OcrDocumentInput
    {
        return new OcrDocumentInput('image-bytes', 'image/png', 'fixture.png', 1, new AiOperationContext(
            $correlationId, AiOperationContext::deterministicId($correlationId.'|base'),
            1, 2, 3, 'understand_documents', 'ocr', 1, 4, null, 5,
        ));
    }

    /** @return array<string, mixed> */
    private function successPayload(string $model): array
    {
        return ['model' => $model, 'choices' => [['message' => ['content' => '{"pages":[{"page_number":1,"text":"ok"}]}']]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12]];
    }

    private function store(): RecordingAiUsageStore
    {
        return new RecordingAiUsageStore;
    }
}

final class RecordingAiUsageStore implements AiUsageStore
{
    /** @var array<int, AiUsageData> */
    public array $rows = [];

    public function record(AiUsageData $data): void
    {
        $this->rows[] = $data;
    }
}
