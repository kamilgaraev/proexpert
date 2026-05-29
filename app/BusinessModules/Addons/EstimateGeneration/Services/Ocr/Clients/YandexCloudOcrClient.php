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

class YandexCloudOcrClient implements OcrClientInterface
{
    public const PROVIDER = 'yandex_cloud_ocr';

    public function recognize(OcrDocumentInput $input): OcrRecognitionResult
    {
        $endpoint = (string) config('estimate-generation.ocr.yandex.endpoint', '');
        $folderId = (string) config('estimate-generation.ocr.yandex.folder_id', '');
        $model = (string) config('estimate-generation.ocr.model', 'page');
        $retryAttempts = max(1, (int) config('estimate-generation.ocr.retry_attempts', 3));
        $retryDelayMs = max(0, (int) config('estimate-generation.ocr.retry_delay_ms', 250));

        if ($endpoint === '' || $folderId === '') {
            throw new OcrConfigurationException();
        }

        $headers = $this->headers($folderId);

        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry($retryAttempts, $retryDelayMs, throw: false)
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->post($endpoint, [
                    'mimeType' => $input->mimeType,
                    'languageCodes' => array_values((array) config('estimate-generation.ocr.languages', ['ru', 'en'])),
                    'model' => $model,
                    'content' => base64_encode($input->content),
                ]);
        } catch (ConnectionException | RequestException $exception) {
            Log::warning('[EstimateGeneration OCR] Yandex OCR request failed', [
                'provider' => self::PROVIDER,
                'mime_type' => $input->mimeType,
                'content_bytes' => strlen($input->content),
                'error' => $exception->getMessage(),
            ]);

            throw new OcrProviderException(
                'estimate_generation.ocr_provider_unavailable',
                previous: $exception
            );
        }

        if (!$response->successful()) {
            $status = $response->status();
            $payload = $response->json();
            $providerCode = is_array($payload) ? (string) Arr::get($payload, 'code', '') : null;

            Log::warning('[EstimateGeneration OCR] Yandex OCR returned error', [
                'provider' => self::PROVIDER,
                'mime_type' => $input->mimeType,
                'content_bytes' => strlen($input->content),
                'status' => $status,
                'provider_code' => $providerCode,
            ]);

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

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new OcrProviderException('estimate_generation.ocr_malformed_response');
        }

        return new OcrRecognitionResult(
            provider: self::PROVIDER,
            model: $model,
            pages: $this->pagesFromPayload($payload),
            rawPayload: $payload,
            metadata: [
                'mime_type' => $input->mimeType,
                'filename' => $input->filename,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $folderId): array
    {
        $authMode = (string) config('estimate-generation.ocr.yandex.auth_mode', 'api_key');
        $apiKey = (string) config('estimate-generation.ocr.yandex.api_key', '');
        $iamToken = (string) config('estimate-generation.ocr.yandex.iam_token', '');

        $headers = [
            'Content-Type' => 'application/json',
            'x-folder-id' => $folderId,
        ];

        if ($authMode === 'iam_token' || $authMode === 'bearer') {
            if ($iamToken === '') {
                throw new OcrConfigurationException('estimate_generation.ocr_not_configured');
            }

            $headers['Authorization'] = 'Bearer ' . $iamToken;

            return $headers;
        }

        if ($apiKey === '') {
            throw new OcrConfigurationException('estimate_generation.ocr_not_configured');
        }

        $headers['Authorization'] = 'Api-Key ' . $apiKey;

        return $headers;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, OcrPageResult>
     */
    private function pagesFromPayload(array $payload): array
    {
        $result = Arr::get($payload, 'result', $payload);
        $annotation = Arr::get($result, 'textAnnotation', Arr::get($result, 'text_annotation', []));

        if (!is_array($annotation)) {
            return [new OcrPageResult(pageNumber: 1, text: '', rawPayload: $payload)];
        }

        $pages = Arr::get($annotation, 'pages');

        if (is_array($pages) && $pages !== []) {
            return array_values(array_map(
                fn (array $page, int $index): OcrPageResult => $this->pageFromPayload($page, $index + 1),
                $pages,
                array_keys($pages),
            ));
        }

        return [
            $this->pageFromPayload($annotation, 1),
        ];
    }

    /**
     * @param array<string, mixed> $page
     */
    private function pageFromPayload(array $page, int $pageNumber): OcrPageResult
    {
        $blocks = Arr::get($page, 'blocks', []);
        $normalizedBlocks = is_array($blocks) ? $this->normalizeBlocks($blocks) : [];
        $text = $this->textFromPage($page, $normalizedBlocks);

        return new OcrPageResult(
            pageNumber: (int) Arr::get($page, 'page', Arr::get($page, 'pageNumber', $pageNumber)),
            text: $text,
            blocks: $normalizedBlocks,
            width: $this->nullableInt(Arr::get($page, 'width')),
            height: $this->nullableInt(Arr::get($page, 'height')),
            rotation: $this->nullableInt(Arr::get($page, 'rotation')),
            confidence: $this->averageConfidence($normalizedBlocks),
            languageCodes: $this->languageCodes($normalizedBlocks),
            rawPayload: $page,
        );
    }

    /**
     * @param array<int, mixed> $blocks
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBlocks(array $blocks): array
    {
        return array_values(array_map(function (mixed $block): array {
            $block = is_array($block) ? $block : [];
            $lines = Arr::get($block, 'lines', []);

            return [
                'text' => (string) Arr::get($block, 'text', ''),
                'bounding_box' => Arr::get($block, 'boundingBox', Arr::get($block, 'bounding_box')),
                'lines' => is_array($lines) ? $this->normalizeLines($lines) : [],
            ];
        }, $blocks));
    }

    /**
     * @param array<int, mixed> $lines
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLines(array $lines): array
    {
        return array_values(array_map(function (mixed $line): array {
            $line = is_array($line) ? $line : [];
            $words = Arr::get($line, 'words', []);

            return [
                'text' => (string) Arr::get($line, 'text', ''),
                'bounding_box' => Arr::get($line, 'boundingBox', Arr::get($line, 'bounding_box')),
                'words' => is_array($words) ? $this->normalizeWords($words) : [],
            ];
        }, $lines));
    }

    /**
     * @param array<int, mixed> $words
     * @return array<int, array<string, mixed>>
     */
    private function normalizeWords(array $words): array
    {
        return array_values(array_map(static function (mixed $word): array {
            $word = is_array($word) ? $word : [];

            return [
                'text' => (string) Arr::get($word, 'text', ''),
                'confidence' => is_numeric(Arr::get($word, 'confidence')) ? (float) Arr::get($word, 'confidence') : null,
                'languages' => array_values((array) Arr::get($word, 'languages', [])),
                'bounding_box' => Arr::get($word, 'boundingBox', Arr::get($word, 'bounding_box')),
            ];
        }, $words));
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, array<string, mixed>> $blocks
     */
    private function textFromPage(array $page, array $blocks): string
    {
        $directText = Arr::get($page, 'fullText', Arr::get($page, 'text'));

        if (is_string($directText) && trim($directText) !== '') {
            return trim($directText);
        }

        $lines = [];

        foreach ($blocks as $block) {
            foreach ($block['lines'] ?? [] as $line) {
                $lineText = trim((string) ($line['text'] ?? ''));

                if ($lineText === '') {
                    $lineText = trim(implode(' ', array_filter(array_map(
                        static fn (array $word): string => trim((string) ($word['text'] ?? '')),
                        $line['words'] ?? [],
                    ))));
                }

                if ($lineText !== '') {
                    $lines[] = $lineText;
                }
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function averageConfidence(array $blocks): ?float
    {
        $values = [];

        foreach ($blocks as $block) {
            foreach ($block['lines'] ?? [] as $line) {
                foreach ($line['words'] ?? [] as $word) {
                    if (is_numeric($word['confidence'] ?? null)) {
                        $values[] = (float) $word['confidence'];
                    }
                }
            }
        }

        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 4);
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, string>
     */
    private function languageCodes(array $blocks): array
    {
        $codes = [];

        foreach ($blocks as $block) {
            foreach ($block['lines'] ?? [] as $line) {
                foreach ($line['words'] ?? [] as $word) {
                    foreach ((array) ($word['languages'] ?? []) as $language) {
                        if (is_string($language) && $language !== '') {
                            $codes[] = $language;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($codes));
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
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
