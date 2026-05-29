<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\YandexCloudOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexCloudOcrClientTest extends TestCase
{
    public function test_it_sends_recognize_text_request_and_normalizes_response(): void
    {
        $this->configureClient();

        Http::fake([
            'https://ocr.example/recognizeText' => Http::response([
                'result' => [
                    'textAnnotation' => [
                        'blocks' => [
                            [
                                'lines' => [
                                    [
                                        'words' => [
                                            [
                                                'text' => 'Склад',
                                                'confidence' => 0.92,
                                                'languages' => ['ru'],
                                            ],
                                            [
                                                'text' => '1200',
                                                'confidence' => 0.88,
                                                'languages' => ['ru'],
                                            ],
                                            [
                                                'text' => 'м2',
                                                'confidence' => 0.9,
                                                'languages' => ['ru'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
            content: 'binary-content',
            mimeType: 'image/png',
            filename: 'plan.png',
        ));

        $this->assertSame(YandexCloudOcrClient::PROVIDER, $result->provider);
        $this->assertSame('page', $result->model);
        $this->assertSame("Склад 1200 м2", $result->text());
        $this->assertCount(1, $result->pages);
        $this->assertSame(0.9, $result->pages[0]->confidence);
        $this->assertSame(['ru'], $result->pages[0]->languageCodes);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ocr.example/recognizeText'
                && $request->hasHeader('Authorization', 'Api-Key test-api-key')
                && $request->hasHeader('x-folder-id', 'folder-1')
                && $payload['mimeType'] === 'image/png'
                && $payload['languageCodes'] === ['ru', 'en']
                && $payload['model'] === 'page'
                && $payload['content'] === base64_encode('binary-content');
        });
    }

    public function test_it_supports_iam_token_authorization(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.yandex.auth_mode' => 'iam_token',
            'estimate-generation.ocr.yandex.iam_token' => 'iam-token',
            'estimate-generation.ocr.yandex.api_key' => null,
        ]);

        Http::fake([
            'https://ocr.example/recognizeText' => Http::response([
                'result' => [
                    'textAnnotation' => [
                        'text' => 'Готовый текст',
                    ],
                ],
            ]),
        ]);

        (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
            content: 'image',
            mimeType: 'image/jpeg',
        ));

        Http::assertSent(static fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer iam-token'));
    }

    public function test_it_uses_async_recognition_for_pdf_documents(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.yandex.async_endpoint' => 'https://ocr.example/recognizeTextAsync',
            'estimate-generation.ocr.yandex.operations_endpoint' => 'https://operation.example/operations',
            'estimate-generation.ocr.yandex.get_recognition_endpoint' => 'https://ocr.example/getRecognition',
            'estimate-generation.ocr.yandex.async_pdf_enabled' => true,
            'estimate-generation.ocr.yandex.async_max_wait_seconds' => 1,
            'estimate-generation.ocr.yandex.async_poll_interval_ms' => 1,
        ]);

        Http::fake([
            'https://ocr.example/recognizeText' => Http::response([
                'code' => 'BAD_REQUEST',
            ], 400),
            'https://ocr.example/recognizeTextAsync' => Http::response([
                'id' => 'operation-1',
                'done' => false,
            ]),
            'https://operation.example/operations/operation-1' => Http::sequence()
                ->push(['id' => 'operation-1', 'done' => false])
                ->push([
                    'id' => 'operation-1',
                    'done' => true,
                    'response' => [
                        'result' => [
                            'textAnnotation' => [
                                'pages' => [
                                    [
                                        'page' => 1,
                                        'text' => 'Общая площадь дома 151,76 м2',
                                    ],
                                    [
                                        'page' => 2,
                                        'text' => 'Жилая площадь 80,21 м2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ]),
        ]);

        $result = (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
            content: '%PDF document content',
            mimeType: 'application/pdf',
            filename: 'project.pdf',
            pageCount: 1,
        ));

        $this->assertSame('Общая площадь дома 151,76 м2' . "\n" . 'Жилая площадь 80,21 м2', $result->text());
        $this->assertCount(2, $result->pages);
        $this->assertTrue($result->metadata['async'] ?? false);
        $this->assertSame('operation-1', $result->metadata['operation_id'] ?? null);

        Http::assertNotSent(static fn (Request $request): bool => $request->url() === 'https://ocr.example/recognizeText');
        Http::assertSent(static function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://ocr.example/recognizeTextAsync'
                && $payload['mimeType'] === 'application/pdf'
                && $payload['content'] === base64_encode('%PDF document content');
        });
        Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://operation.example/operations/operation-1');
    }

    public function test_it_reads_async_recognition_result_from_get_recognition_json_lines(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.yandex.async_endpoint' => 'https://ocr.example/recognizeTextAsync',
            'estimate-generation.ocr.yandex.operations_endpoint' => 'https://operation.example/operations',
            'estimate-generation.ocr.yandex.get_recognition_endpoint' => 'https://ocr.example/getRecognition',
            'estimate-generation.ocr.yandex.async_pdf_enabled' => true,
            'estimate-generation.ocr.yandex.async_max_wait_seconds' => 1,
            'estimate-generation.ocr.yandex.async_poll_interval_ms' => 1,
        ]);

        Http::fake([
            'https://ocr.example/recognizeTextAsync' => Http::response([
                'id' => 'operation-2',
                'done' => false,
            ]),
            'https://operation.example/operations/operation-2' => Http::response([
                'id' => 'operation-2',
                'done' => true,
            ]),
            'https://ocr.example/getRecognition*' => Http::response(implode("\n", [
                json_encode([
                    'result' => [
                        'text_annotation' => [
                            'fullText' => 'Page one',
                        ],
                        'page' => '0',
                    ],
                ], JSON_THROW_ON_ERROR),
                json_encode([
                    'result' => [
                        'text_annotation' => [
                            'fullText' => 'Page two',
                        ],
                        'page' => '1',
                    ],
                ], JSON_THROW_ON_ERROR),
            ])),
        ]);

        $result = (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
            content: '%PDF document content',
            mimeType: 'application/pdf',
            filename: 'project.pdf',
            pageCount: 1,
        ));

        $this->assertSame("Page one\nPage two", $result->text());
        $this->assertSame([1, 2], array_map(static fn (OcrPageResult $page): int => $page->pageNumber, $result->pages));

        Http::assertSent(static fn (Request $request): bool => str_starts_with($request->url(), 'https://ocr.example/getRecognition'));
    }

    public function test_it_fails_before_http_call_when_credentials_are_missing(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.yandex.api_key' => null,
        ]);

        $this->expectException(OcrConfigurationException::class);

        try {
            (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
                content: 'binary-content',
                mimeType: 'image/png',
            ));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_maps_provider_statuses_to_domain_errors(): void
    {
        $this->configureClient();

        Http::fake([
            'https://ocr.example/recognizeText' => Http::response([
                'code' => 'RESOURCE_EXHAUSTED',
            ], 429),
        ]);

        try {
            (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
                content: 'binary-content',
                mimeType: 'image/png',
            ));

            $this->fail('Expected OCR provider exception.');
        } catch (OcrProviderException $exception) {
            $this->assertSame('estimate_generation.ocr_quota_exceeded', $exception->messageKey);
            $this->assertSame(429, $exception->statusCode);
            $this->assertSame('RESOURCE_EXHAUSTED', $exception->providerCode);
        }
    }

    public function test_it_retries_transient_provider_errors(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.retry_attempts' => 2,
            'estimate-generation.ocr.retry_delay_ms' => 1,
        ]);

        Http::fake([
            'https://ocr.example/recognizeText' => Http::sequence()
                ->push(['code' => 'UNAVAILABLE'], 503)
                ->push([
                    'result' => [
                        'textAnnotation' => [
                            'text' => 'Готовый текст',
                        ],
                    ],
                ]),
        ]);

        $result = (new YandexCloudOcrClient())->recognize(new OcrDocumentInput(
            content: 'binary-content',
            mimeType: 'image/png',
        ));

        $this->assertSame('Готовый текст', $result->text());
        Http::assertSentCount(2);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureClient(array $overrides = []): void
    {
        $defaults = [
            'estimate-generation.ocr.yandex.endpoint' => 'https://ocr.example/recognizeText',
            'estimate-generation.ocr.yandex.folder_id' => 'folder-1',
            'estimate-generation.ocr.yandex.api_key' => 'test-api-key',
            'estimate-generation.ocr.yandex.iam_token' => null,
            'estimate-generation.ocr.yandex.auth_mode' => 'api_key',
            'estimate-generation.ocr.languages' => ['ru', 'en'],
            'estimate-generation.ocr.model' => 'page',
            'estimate-generation.ocr.timeout_seconds' => 60,
            'estimate-generation.ocr.retry_attempts' => 3,
            'estimate-generation.ocr.retry_delay_ms' => 250,
            'estimate-generation.ocr.yandex.async_endpoint' => 'https://ocr.example/recognizeTextAsync',
            'estimate-generation.ocr.yandex.operations_endpoint' => 'https://operation.example/operations',
            'estimate-generation.ocr.yandex.get_recognition_endpoint' => 'https://ocr.example/getRecognition',
            'estimate-generation.ocr.yandex.async_pdf_enabled' => false,
            'estimate-generation.ocr.yandex.async_max_wait_seconds' => 60,
            'estimate-generation.ocr.yandex.async_poll_interval_ms' => 250,
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            config()->set($key, $value);
        }
    }
}
