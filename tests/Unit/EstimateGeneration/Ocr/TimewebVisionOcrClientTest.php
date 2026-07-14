<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients\TimewebVisionOcrClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TimewebVisionOcrClientTest extends TestCase
{
    public function test_it_sends_image_to_timeweb_chat_completions_and_normalizes_json_response(): void
    {
        $this->configureClient();

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'model' => 'gemini/gemini-3.1-flash-lite',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'pages' => [
                                    [
                                        'page_number' => 1,
                                        'text' => 'Склад 1200 м2',
                                        'confidence' => 0.91,
                                        'language_codes' => ['ru'],
                                    ],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 140,
                    'completion_tokens' => 24,
                    'total_tokens' => 164,
                ],
            ]),
        ]);

        $result = (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
            content: 'binary-content',
            mimeType: 'image/png',
            filename: 'plan.png',
        ));

        $this->assertSame(TimewebVisionOcrClient::PROVIDER, $result->provider);
        $this->assertSame('gemini/gemini-3.1-flash-lite', $result->model);
        $this->assertSame('Склад 1200 м2', $result->text());
        $this->assertSame(0.91, $result->pages[0]->confidence);
        $this->assertSame(['ru'], $result->pages[0]->languageCodes);
        $this->assertSame([
            'input_tokens' => 140,
            'output_tokens' => 24,
            'total_tokens' => 164,
        ], $result->metadata['usage']);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $content = $payload['messages'][1]['content'] ?? [];
            $imagePart = $content[1] ?? [];

            return $request->url() === 'https://api.timeweb.ai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer timeweb-key')
                && $payload['model'] === 'gemini/gemini-3.1-flash-lite'
                && $payload['temperature'] === 0
                && $imagePart['type'] === 'image_url'
                && $imagePart['image_url']['url'] === 'data:image/png;base64,'.base64_encode('binary-content')
                && $imagePart['image_url']['detail'] === 'high';
        });
    }

    public function test_it_uses_the_pinned_model_and_input_file_part_for_pdf_documents(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.model' => 'openai/gpt-5-mini',
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'model' => 'openai/gpt-5-mini',
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'pages' => [
                                    ['page_number' => 1, 'text' => 'Первая страница'],
                                    ['page_number' => 2, 'text' => 'Вторая страница'],
                                ],
                            ], JSON_THROW_ON_ERROR),
                        ],
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 200,
                    'completion_tokens' => 40,
                    'total_tokens' => 240,
                ],
            ]),
        ]);

        $result = (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
            content: '%PDF content',
            mimeType: 'application/pdf',
            filename: 'project.pdf',
            pageCount: 2,
        ));

        $this->assertSame("Первая страница\nВторая страница", $result->text());
        $this->assertSame('openai/gpt-5-mini', $result->model);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $filePart = $payload['messages'][1]['content'][1] ?? [];

            return $payload['model'] === 'openai/gpt-5-mini'
                && $filePart['type'] === 'input_file'
                && $filePart['filename'] === 'project.pdf'
                && $filePart['file_data'] === 'data:application/pdf;base64,'.base64_encode('%PDF content');
        });
    }

    public function test_it_retries_the_same_pinned_model_after_provider_error(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.model' => 'gemini/gemini-3.1-flash-lite',
            'estimate-generation.ocr.retry_attempts' => 2,
        ]);

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['code' => 'model_failed']], 503)
                ->push([
                    'model' => 'gemini/gemini-3.1-flash-lite',
                    'choices' => [
                        [
                            'message' => [
                                'content' => '{"pages":[{"page_number":1,"text":"Готовый текст"}]}',
                            ],
                        ],
                    ],
                ]),
        ]);

        $result = (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
            content: 'image',
            mimeType: 'image/jpeg',
        ));

        $this->assertSame('gemini/gemini-3.1-flash-lite', $result->model);
        $this->assertSame('Готовый текст', $result->text());
        Http::assertSentCount(2);
    }

    public function test_it_fails_before_http_call_when_credentials_are_missing(): void
    {
        $this->configureClient([
            'estimate-generation.ocr.timeweb.api_key' => '',
        ]);

        $this->expectException(OcrConfigurationException::class);

        try {
            (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
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
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'error' => [
                    'code' => 'rate_limit_exceeded',
                ],
            ], 429),
        ]);

        try {
            (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
                content: 'binary-content',
                mimeType: 'image/png',
            ));

            $this->fail('Expected OCR provider exception.');
        } catch (OcrProviderException $exception) {
            $this->assertSame('estimate_generation.ocr_quota_exceeded', $exception->messageKey);
            $this->assertSame(429, $exception->statusCode);
            $this->assertSame('rate_limit_exceeded', $exception->providerCode);
        }
    }

    public function test_it_uses_plain_text_when_model_returns_non_json_content(): void
    {
        $this->configureClient();

        Http::fake([
            'https://api.timeweb.ai/v1/chat/completions' => Http::response([
                'model' => 'gemini/gemini-3.1-flash-lite',
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Просто распознанный текст',
                        ],
                    ],
                ],
            ]),
        ]);

        $result = (new TimewebVisionOcrClient)->recognize(new OcrDocumentInput(
            content: 'image',
            mimeType: 'image/jpeg',
        ));

        $this->assertSame('Просто распознанный текст', $result->text());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureClient(array $overrides = []): void
    {
        $defaults = [
            'estimate-generation.ocr.timeweb.api_key' => 'timeweb-key',
            'estimate-generation.ocr.timeweb.base_uri' => 'https://api.timeweb.ai/v1',
            'estimate-generation.ocr.model' => 'gemini/gemini-3.1-flash-lite',
            'estimate-generation.ocr.timeweb.image_detail' => 'high',
            'estimate-generation.ocr.max_tokens' => 4096,
            'estimate-generation.ocr.timeout_seconds' => 60,
            'estimate-generation.ocr.retry_attempts' => 1,
            'estimate-generation.ocr.retry_delay_ms' => 1,
        ];

        foreach (array_merge($defaults, $overrides) as $key => $value) {
            config()->set($key, $value);
        }
    }
}
