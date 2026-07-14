<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiAttemptAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\TimewebVisionOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
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
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function replay_without_claim_never_calls_ocr_wire(): void
    {
        $this->configure(['model-a'], 1);
        Http::fake();
        $store = $this->store();
        $authorizer = new RejectingOcrWireClaimAuthorizer;
        $client = new TimewebVisionOcrClient($store, null, $authorizer);

        try {
            $client->recognize($this->input());
            self::fail('Replay without claim reached OCR wire.');
        } catch (OcrProviderException $exception) {
            self::assertSame('wire_replay_forbidden', $exception->providerCode);
        }

        Http::assertNothingSent();
        self::assertSame([], $store->rows);
        self::assertSame(0, $authorizer->releases);

        $authorizer->claimGranted = true;
        Http::swap(new Factory);
        Http::fake(fn () => Http::response($this->successPayload('model-a'), 200));
        $result = $client->recognize($this->input());

        self::assertSame('model-a', $result->model);
        self::assertCount(1, $store->rows);
        self::assertSame('succeeded', $store->rows[0]->status);
        self::assertSame('measured', $store->rows[0]->usageStatus);
        self::assertSame($authorizer->attemptIds[0], $authorizer->attemptIds[1]);
        self::assertSame($authorizer->attemptIds[0], $store->rows[0]->context->attemptId);
        self::assertSame(0, $authorizer->releases);
    }

    #[Test]
    public function internal_retry_records_http_failure_then_success_exactly_once_each(): void
    {
        $store = $this->store();
        $this->configure(['model-a'], 2);
        Http::fakeSequence()
            ->push(['error' => ['code' => 'temporary']], 500)
            ->push($this->successPayload('model-a'), 200);

        (new TimewebVisionOcrClient($store))->recognize($this->input());

        self::assertSame(['http_failed', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
        self::assertSame(['unavailable', 'measured'], array_map(fn (AiUsageData $row): string => $row->usageStatus, $store->rows));
    }

    #[Test]
    public function arbitrary_non_json_response_is_malformed_and_falls_back_to_next_model(): void
    {
        $store = $this->store();
        $this->configure(['model-a', 'model-b'], 1);
        Http::fakeSequence()
            ->push(['model' => 'model-a', 'choices' => [['message' => ['content' => 'plain OCR text']]]], 200)
            ->push($this->successPayload('model-b'), 200);

        $result = (new TimewebVisionOcrClient($store))->recognize($this->input());

        self::assertSame('model-b', $result->model);
        self::assertSame(['malformed_response', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
        self::assertCount(2, $store->rows);
    }

    #[Test]
    public function replay_keeps_attempt_id_new_claim_changes_it_and_recorder_failure_never_masks_success(): void
    {
        $store = $this->store();
        $this->configure(['model-a'], 1);
        Http::fake(fn () => Http::response($this->successPayload('model-a'), 200));
        $client = new TimewebVisionOcrClient($store);

        $client->recognize($this->input());
        $client->recognize($this->input());
        $client->recognize($this->input('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7e'));

        self::assertSame($store->rows[0]->context->attemptId, $store->rows[1]->context->attemptId);
        self::assertSame($store->rows[0]->context->correlationId, $store->rows[1]->context->correlationId);
        self::assertNotSame($store->rows[0]->context->attemptId, $store->rows[2]->context->attemptId);

        $throwing = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void
            {
                throw new \RuntimeException('recorder failed');
            }
        };
        self::assertSame('model-a', (new TimewebVisionOcrClient($throwing))->recognize($this->input())->model);
    }

    #[Test]
    public function reported_model_mismatch_is_malformed_and_routes_to_next_model(): void
    {
        $store = $this->store();
        $this->configure(['model-a', 'model-b'], 1);
        Http::fakeSequence()->push($this->successPayload('unexpected-model'), 200)->push($this->successPayload('model-b'), 200);

        self::assertSame('model-b', (new TimewebVisionOcrClient($store))->recognize($this->input())->model);
        self::assertSame(['malformed_response', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
    }

    #[Test]
    public function connection_retry_and_terminal_http_policy_have_exact_attempt_rows(): void
    {
        $store = $this->store();
        $this->configure(['model-a'], 2);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                throw new ConnectionException('offline');
            }

            return Http::response($this->successPayload('model-a'), 200);
        });
        (new TimewebVisionOcrClient($store))->recognize($this->input());
        self::assertSame(['connection_failed', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));

        $terminalStore = $this->store();
        Http::swap(new Factory);
        $this->configure(['model-a', 'model-b'], 3);
        Http::fake(fn () => Http::response(['error' => ['code' => 'unauthorized']], 401));
        try {
            (new TimewebVisionOcrClient($terminalStore))->recognize($this->input());
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
        config()->set('estimate-generation.ocr.timeweb.models', $models);
        config()->set('estimate-generation.ocr.retry_attempts', $retries);
        config()->set('estimate-generation.ocr.retry_delay_ms', 0);
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

final class RejectingOcrWireClaimAuthorizer implements AiAttemptAuthorizer
{
    public bool $claimGranted = false;

    public int $releases = 0;

    /** @var list<string> */
    public array $attemptIds = [];

    public function authorize(
        AiOperationContext $context,
        string $provider,
        string $model,
        int $maxInputTokens,
        int $maxOutputTokens,
        int $imageCount = 0,
        int $pageCount = 0,
    ): AiPriceSnapshot {
        return AiPriceSnapshot::fromArray([]);
    }

    public function claimWire(string $attemptId): bool
    {
        $this->attemptIds[] = $attemptId;

        return $this->claimGranted;
    }

    public function releaseBeforeWire(string $attemptId): void
    {
        $this->releases++;
    }
}
