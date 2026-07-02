<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Clients;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TimewebVisionOcrClient implements OcrClientInterface
{
    public const PROVIDER = 'timeweb';

    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
    {
        $apiKey = trim((string) config('estimate-generation.ocr.timeweb.api_key', ''));
        $baseUri = rtrim(trim((string) config('estimate-generation.ocr.timeweb.base_uri', 'https://api.timeweb.ai/v1')), '/');
        $models = $this->modelsFor($input);

        if ($apiKey === '' || $baseUri === '' || $models === []) {
            throw new OcrConfigurationException;
        }

        $lastException = null;

        foreach ($models as $attempt => $model) {
            try {
                return $this->recognizeWithModel($input, $baseUri, $apiKey, $model, $attempt + 1);
            } catch (OcrProviderException $exception) {
                $lastException = $exception;

                Log::warning('[EstimateGeneration OCR] Timeweb OCR model failed', [
                    'provider' => self::PROVIDER,
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'mime_type' => $input->mimeType,
                    'status' => $exception->statusCode,
                    'provider_code' => $exception->providerCode,
                ]);
            }
        }

        if ($lastException instanceof OcrProviderException) {
            throw $lastException;
        }

        throw new OcrProviderException('estimate_generation.ocr_provider_unavailable');
    }

    private function recognizeWithModel(
        OcrDocumentInput $input,
        string $baseUri,
        string $apiKey,
        string $model,
        int $attempt
    ): OcrRecognitionResult {
        $requestPayload = [
            'model' => $model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->systemPrompt(),
                ],
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $this->userPrompt($input),
                        ],
                        $this->documentPart($input),
                    ],
                ],
            ],
            'temperature' => 0,
            'max_tokens' => max(512, (int) config('estimate-generation.ocr.max_tokens', 4096)),
            'response_format' => [
                'type' => 'json_object',
            ],
        ];

        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry(
                    max(1, (int) config('estimate-generation.ocr.retry_attempts', 3)),
                    max(0, (int) config('estimate-generation.ocr.retry_delay_ms', 250)),
                    throw: false
                )
                ->acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->post($baseUri.'/chat/completions', $requestPayload);
        } catch (ConnectionException|RequestException $exception) {
            throw new OcrProviderException(
                'estimate_generation.ocr_provider_unavailable',
                providerCode: 'timeweb_request_failed',
                previous: $exception
            );
        }

        $payload = $response->json();
        $payload = is_array($payload) ? $payload : [];

        if (! $response->successful()) {
            $this->throwHttpProviderException($response->status(), $payload);
        }

        $content = trim((string) Arr::get($payload, 'choices.0.message.content', ''));

        if ($content === '') {
            throw new OcrProviderException(
                'estimate_generation.ocr_malformed_response',
                providerCode: 'empty_timeweb_response'
            );
        }

        $usage = $this->usageFromPayload($payload);

        return new OcrRecognitionResult(
            provider: self::PROVIDER,
            model: (string) Arr::get($payload, 'model', $model),
            pages: $this->pagesFromContent($content),
            rawPayload: $payload,
            metadata: [
                'mime_type' => $input->mimeType,
                'filename' => $input->filename,
                'route_attempt' => $attempt,
                'usage' => $usage,
                'finish_reason' => Arr::get($payload, 'choices.0.finish_reason'),
            ],
        );
    }

    /**
     * @return array<int, string>
     */
    private function modelsFor(OcrDocumentInput $input): array
    {
        $configKey = $this->isPdf($input) ? 'pdf_models' : 'models';
        $models = config("estimate-generation.ocr.timeweb.{$configKey}");

        if ($models === null || $models === '') {
            $models = config('estimate-generation.ocr.model', 'gemini/gemini-3.1-flash-lite');
        }

        if (is_string($models)) {
            $models = explode(',', $models);
        }

        if (! is_array($models)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            $models
        ))));
    }

    /**
     * @return array<string, mixed>
     */
    private function documentPart(OcrDocumentInput $input): array
    {
        $dataUrl = sprintf('data:%s;base64,%s', $input->mimeType, base64_encode($input->content));

        if ($this->isPdf($input)) {
            return [
                'type' => 'input_file',
                'filename' => $input->filename ?: 'document.pdf',
                'file_data' => $dataUrl,
            ];
        }

        if (! $this->isImage($input)) {
            throw new OcrProviderException(
                'estimate_generation.ocr_unsupported_file',
                415,
                'unsupported_timeweb_ocr_mime_type',
                ['mime_type' => $input->mimeType]
            );
        }

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => $dataUrl,
                'detail' => (string) config('estimate-generation.ocr.timeweb.image_detail', 'high'),
            ],
        ];
    }

    private function systemPrompt(): string
    {
        return implode("\n", [
            'Ты OCR-модуль строительной системы.',
            'Извлекай только видимый текст из документа, без догадок и объяснений.',
            'Сохраняй переносы строк, числа, единицы измерения, обозначения осей, марки, даты, суммы и таблицы в читаемом текстовом виде.',
            'Верни строго JSON без markdown.',
        ]);
    }

    private function userPrompt(OcrDocumentInput $input): string
    {
        $filename = $input->filename ?: 'без названия';
        $pages = $input->pageCount !== null ? (string) $input->pageCount : 'неизвестно';

        return implode("\n", [
            "Файл: {$filename}",
            "MIME: {$input->mimeType}",
            "Страниц: {$pages}",
            'Формат ответа:',
            '{"pages":[{"page_number":1,"text":"распознанный текст страницы","confidence":0.8}]}',
            'Если страниц несколько, верни каждую страницу отдельным элементом массива pages.',
        ]);
    }

    /**
     * @return array<int, OcrPageResult>
     */
    private function pagesFromContent(string $content): array
    {
        $decoded = $this->decodeJsonContent($content);

        if ($decoded === null) {
            return [
                new OcrPageResult(
                    pageNumber: 1,
                    text: $content,
                    rawPayload: ['content' => $content],
                ),
            ];
        }

        $pages = Arr::get($decoded, 'pages');

        if (! is_array($pages) || $pages === []) {
            $text = trim((string) Arr::get($decoded, 'text', ''));

            return [
                new OcrPageResult(
                    pageNumber: 1,
                    text: $text,
                    confidence: $this->nullableFloat(Arr::get($decoded, 'confidence')),
                    rawPayload: $decoded,
                ),
            ];
        }

        $normalized = [];

        foreach (array_values($pages) as $index => $page) {
            $page = is_array($page) ? $page : [];
            $text = trim((string) Arr::get($page, 'text', ''));

            $normalized[] = new OcrPageResult(
                pageNumber: $this->pageNumber(Arr::get($page, 'page_number', Arr::get($page, 'page')), $index + 1),
                text: $text,
                blocks: $this->blocks(Arr::get($page, 'blocks', [])),
                width: $this->nullableInt(Arr::get($page, 'width')),
                height: $this->nullableInt(Arr::get($page, 'height')),
                rotation: $this->nullableInt(Arr::get($page, 'rotation')),
                confidence: $this->nullableFloat(Arr::get($page, 'confidence')),
                languageCodes: array_values(array_filter(array_map(
                    static fn (mixed $code): string => trim((string) $code),
                    (array) Arr::get($page, 'language_codes', [])
                ))),
                rawPayload: $page,
            );
        }

        return $normalized !== []
            ? $normalized
            : [new OcrPageResult(pageNumber: 1, text: '', rawPayload: $decoded)];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonContent(string $content): ?array
    {
        $candidate = trim($content);
        $candidate = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $candidate) ?? $candidate;
        $candidate = trim($candidate);

        $decoded = json_decode($candidate, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $start = strpos($candidate, '{');
        $end = strrpos($candidate, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($candidate, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int}
     */
    private function usageFromPayload(array $payload): array
    {
        $inputTokens = (int) Arr::get($payload, 'usage.prompt_tokens', Arr::get($payload, 'usage.input_tokens', 0));
        $outputTokens = (int) Arr::get($payload, 'usage.completion_tokens', Arr::get($payload, 'usage.output_tokens', 0));
        $totalTokens = (int) Arr::get($payload, 'usage.total_tokens', $inputTokens + $outputTokens);

        return [
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'total_tokens' => max(0, $totalTokens),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function throwHttpProviderException(int $status, array $payload): never
    {
        $providerCode = (string) Arr::get(
            $payload,
            'error.code',
            Arr::get($payload, 'error.type', Arr::get($payload, 'code', ''))
        );

        throw new OcrProviderException(
            $this->messageKeyForStatus($status),
            $status,
            $providerCode !== '' ? $providerCode : null,
            [
                'status' => $status,
                'provider_code' => $providerCode,
            ],
        );
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function blocks(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (mixed $block): bool => is_array($block)));
    }

    private function isPdf(OcrDocumentInput $input): bool
    {
        return str_contains(strtolower($input->mimeType), 'pdf')
            || strtolower((string) pathinfo((string) $input->filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    private function isImage(OcrDocumentInput $input): bool
    {
        $extension = strtolower((string) pathinfo((string) $input->filename, PATHINFO_EXTENSION));

        return str_starts_with(strtolower($input->mimeType), 'image/')
            || in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    }

    private function pageNumber(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(1, (int) $value) : $fallback;
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function messageKeyForStatus(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => 'estimate_generation.ocr_auth_error',
            $status === 413 => 'estimate_generation.ocr_file_too_large',
            $status === 415 => 'estimate_generation.ocr_unsupported_file',
            $status === 429 => 'estimate_generation.ocr_quota_exceeded',
            $status >= 500 => 'estimate_generation.ocr_provider_unavailable',
            default => 'estimate_generation.ocr_provider_error',
        };
    }
}
