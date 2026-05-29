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
        $payload = [
            'mimeType' => $input->mimeType,
            'languageCodes' => array_values((array) config('estimate-generation.ocr.languages', ['ru', 'en'])),
            'model' => $model,
            'content' => base64_encode($input->content),
        ];

        if ($this->shouldUseAsyncRecognition($input)) {
            return $this->recognizeAsync($input, $payload, $headers, $model, $retryAttempts, $retryDelayMs);
        }

        return $this->recognizeSync($input, $payload, $endpoint, $headers, $model, $retryAttempts, $retryDelayMs);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function recognizeSync(
        OcrDocumentInput $input,
        array $payload,
        string $endpoint,
        array $headers,
        string $model,
        int $retryAttempts,
        int $retryDelayMs
    ): OcrRecognitionResult {
        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry($retryAttempts, $retryDelayMs, throw: false)
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->post($endpoint, $payload);
        } catch (ConnectionException | RequestException $exception) {
            Log::warning('[EstimateGeneration OCR] Yandex OCR request failed', [
                'provider' => self::PROVIDER,
                'mode' => 'sync',
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
            $providerCode = is_array($payload) ? (string) Arr::get($payload, 'code', Arr::get($payload, 'error.code', '')) : null;

            Log::warning('[EstimateGeneration OCR] Yandex OCR returned error', [
                'provider' => self::PROVIDER,
                'mode' => 'sync',
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
                'async' => false,
            ],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     */
    private function recognizeAsync(
        OcrDocumentInput $input,
        array $payload,
        array $headers,
        string $model,
        int $retryAttempts,
        int $retryDelayMs
    ): OcrRecognitionResult {
        $endpoint = (string) config('estimate-generation.ocr.yandex.async_endpoint', '');
        $operationsEndpoint = rtrim((string) config('estimate-generation.ocr.yandex.operations_endpoint', ''), '/');
        $getRecognitionEndpoint = (string) config('estimate-generation.ocr.yandex.get_recognition_endpoint', '');

        if ($endpoint === '' || $getRecognitionEndpoint === '') {
            throw new OcrConfigurationException();
        }

        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry($retryAttempts, $retryDelayMs, throw: false)
                ->acceptJson()
                ->asJson()
                ->withHeaders($headers)
                ->post($endpoint, $payload);
        } catch (ConnectionException | RequestException $exception) {
            Log::warning('[EstimateGeneration OCR] Yandex async OCR request failed', [
                'provider' => self::PROVIDER,
                'mode' => 'async',
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
            $payload = $response->json();
            $this->throwHttpProviderException(
                $response->status(),
                is_array($payload) ? $payload : null,
                $input,
                'async'
            );
        }

        $operation = $response->json();

        if (!is_array($operation)) {
            throw new OcrProviderException('estimate_generation.ocr_malformed_response');
        }

        $operationId = $this->operationId($operation);

        if ($operationId === null) {
            throw new OcrProviderException(
                'estimate_generation.ocr_malformed_response',
                providerCode: 'missing_operation_id',
            );
        }

        if (Arr::get($operation, 'done') === true && $this->hasRecognitionPayload((array) Arr::get($operation, 'response', []))) {
            return $this->resultFromOperation($operation, $input, $model, $operationId);
        }

        if (Arr::get($operation, 'done') === true) {
            $this->throwIfOperationFailed($operation, $operationId);

            return $this->fetchRecognitionResult(
                $getRecognitionEndpoint,
                $operationId,
                $headers,
                $input,
                $model,
                $retryAttempts,
                $retryDelayMs
            );
        }

        $deadline = microtime(true) + max(1, (int) config('estimate-generation.ocr.yandex.async_max_wait_seconds', 540));
        $pollIntervalMs = max(100, (int) config('estimate-generation.ocr.yandex.async_poll_interval_ms', 1000));

        while ($operationsEndpoint !== '' && microtime(true) <= $deadline) {
            $operation = $this->pollOperation($operationsEndpoint, $operationId, $headers, $retryAttempts, $retryDelayMs);

            if (Arr::get($operation, 'done') === true && $this->hasRecognitionPayload((array) Arr::get($operation, 'response', []))) {
                return $this->resultFromOperation($operation, $input, $model, $operationId);
            }

            if (Arr::get($operation, 'done') === true) {
                $this->throwIfOperationFailed($operation, $operationId);

                return $this->fetchRecognitionResult(
                    $getRecognitionEndpoint,
                    $operationId,
                    $headers,
                    $input,
                    $model,
                    $retryAttempts,
                    $retryDelayMs
                );
            }

            usleep($pollIntervalMs * 1000);
        }

        if ($operationsEndpoint === '') {
            return $this->fetchRecognitionResult(
                $getRecognitionEndpoint,
                $operationId,
                $headers,
                $input,
                $model,
                $retryAttempts,
                $retryDelayMs
            );
        }

        throw new OcrProviderException(
            'estimate_generation.ocr_provider_unavailable',
            providerCode: 'ocr_async_timeout',
            context: [
                'operation_id' => $operationId,
                'max_wait_seconds' => (int) config('estimate-generation.ocr.yandex.async_max_wait_seconds', 540),
            ],
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function fetchRecognitionResult(
        string $endpoint,
        string $operationId,
        array $headers,
        OcrDocumentInput $input,
        string $model,
        int $retryAttempts,
        int $retryDelayMs
    ): OcrRecognitionResult {
        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry($retryAttempts, $retryDelayMs, throw: false)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($endpoint, ['operationId' => $operationId]);
        } catch (ConnectionException | RequestException $exception) {
            Log::warning('[EstimateGeneration OCR] Yandex async OCR result request failed', [
                'provider' => self::PROVIDER,
                'mode' => 'async_result',
                'operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);

            throw new OcrProviderException(
                'estimate_generation.ocr_provider_unavailable',
                providerCode: 'ocr_async_result_failed',
                context: ['operation_id' => $operationId],
                previous: $exception
            );
        }

        if (!$response->successful()) {
            $payload = $response->json();
            $this->throwHttpProviderException(
                $response->status(),
                is_array($payload) ? $payload : null,
                $input,
                'async_result'
            );
        }

        $payload = $this->recognitionPayloadFromResponse($response->json(), $response->body(), $operationId);

        return new OcrRecognitionResult(
            provider: self::PROVIDER,
            model: $model,
            pages: $this->pagesFromPayload($payload),
            rawPayload: $payload,
            metadata: [
                'mime_type' => $input->mimeType,
                'filename' => $input->filename,
                'async' => true,
                'operation_id' => $operationId,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function recognitionPayloadFromResponse(mixed $json, string $body, string $operationId): array
    {
        if (is_array($json)) {
            return $json;
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        $results = [];

        foreach ($lines === false ? [] : $lines as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);

            if (!is_array($decoded)) {
                throw new OcrProviderException(
                    'estimate_generation.ocr_malformed_response',
                    providerCode: 'malformed_recognition_result',
                    context: ['operation_id' => $operationId],
                );
            }

            $results[] = $decoded;
        }

        if ($results === []) {
            throw new OcrProviderException(
                'estimate_generation.ocr_malformed_response',
                providerCode: 'empty_recognition_result',
                context: ['operation_id' => $operationId],
            );
        }

        return ['results' => $results];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hasRecognitionPayload(array $payload): bool
    {
        return is_array(Arr::get($payload, 'result.textAnnotation'))
            || is_array(Arr::get($payload, 'result.text_annotation'))
            || is_array(Arr::get($payload, 'textAnnotation'))
            || is_array(Arr::get($payload, 'text_annotation'))
            || is_array(Arr::get($payload, 'results'));
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function pollOperation(
        string $operationsEndpoint,
        string $operationId,
        array $headers,
        int $retryAttempts,
        int $retryDelayMs
    ): array {
        try {
            $response = Http::timeout((int) config('estimate-generation.ocr.timeout_seconds', 60))
                ->retry($retryAttempts, $retryDelayMs, throw: false)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($operationsEndpoint . '/' . rawurlencode($operationId));
        } catch (ConnectionException | RequestException $exception) {
            Log::warning('[EstimateGeneration OCR] Yandex async OCR polling failed', [
                'provider' => self::PROVIDER,
                'mode' => 'async',
                'operation_id' => $operationId,
                'error' => $exception->getMessage(),
            ]);

            throw new OcrProviderException(
                'estimate_generation.ocr_provider_unavailable',
                providerCode: 'ocr_async_polling_failed',
                context: ['operation_id' => $operationId],
                previous: $exception
            );
        }

        if (!$response->successful()) {
            $payload = $response->json();
            $this->throwHttpProviderException(
                $response->status(),
                is_array($payload) ? $payload : null,
                new OcrDocumentInput(content: '', mimeType: 'application/pdf'),
                'async_polling'
            );
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            throw new OcrProviderException(
                'estimate_generation.ocr_malformed_response',
                providerCode: 'malformed_operation_response',
                context: ['operation_id' => $operationId],
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function throwIfOperationFailed(array $operation, string $operationId): void
    {
        $error = Arr::get($operation, 'error');

        if (!is_array($error)) {
            return;
        }

        $status = is_numeric(Arr::get($error, 'httpCode')) ? (int) Arr::get($error, 'httpCode') : 400;
        $providerCode = (string) Arr::get($error, 'code', 'ocr_async_operation_failed');

        Log::warning('[EstimateGeneration OCR] Yandex async OCR operation returned error', [
            'provider' => self::PROVIDER,
            'mode' => 'async',
            'operation_id' => $operationId,
            'status' => $status,
            'provider_code' => $providerCode,
        ]);

        throw new OcrProviderException(
            $this->messageKeyForStatus($status),
            $status,
            $providerCode,
            [
                'operation_id' => $operationId,
                'provider_code' => $providerCode,
            ],
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function resultFromOperation(
        array $operation,
        OcrDocumentInput $input,
        string $model,
        string $operationId
    ): OcrRecognitionResult {
        $error = Arr::get($operation, 'error');

        if (is_array($error)) {
            $status = is_numeric(Arr::get($error, 'httpCode')) ? (int) Arr::get($error, 'httpCode') : 400;
            $providerCode = (string) Arr::get($error, 'code', 'ocr_async_operation_failed');

            Log::warning('[EstimateGeneration OCR] Yandex async OCR operation returned error', [
                'provider' => self::PROVIDER,
                'mode' => 'async',
                'operation_id' => $operationId,
                'status' => $status,
                'provider_code' => $providerCode,
            ]);

            throw new OcrProviderException(
                $this->messageKeyForStatus($status),
                $status,
                $providerCode,
                [
                    'operation_id' => $operationId,
                    'provider_code' => $providerCode,
                ],
            );
        }

        $payload = Arr::get($operation, 'response');

        if (!is_array($payload)) {
            throw new OcrProviderException(
                'estimate_generation.ocr_malformed_response',
                providerCode: 'missing_operation_response',
                context: ['operation_id' => $operationId],
            );
        }

        return new OcrRecognitionResult(
            provider: self::PROVIDER,
            model: $model,
            pages: $this->pagesFromPayload($payload),
            rawPayload: $payload,
            metadata: [
                'mime_type' => $input->mimeType,
                'filename' => $input->filename,
                'async' => true,
                'operation_id' => $operationId,
            ],
        );
    }

    private function shouldUseAsyncRecognition(OcrDocumentInput $input): bool
    {
        $isPdf = str_contains(strtolower($input->mimeType), 'pdf')
            || strtolower((string) pathinfo((string) $input->filename, PATHINFO_EXTENSION)) === 'pdf';

        return $isPdf
            && $input->pageCount === 1
            && (bool) config('estimate-generation.ocr.yandex.async_pdf_enabled', false);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function throwHttpProviderException(
        int $status,
        ?array $payload,
        OcrDocumentInput $input,
        string $mode
    ): never {
        $providerCode = is_array($payload) ? (string) Arr::get($payload, 'code', Arr::get($payload, 'error.code', '')) : null;

        Log::warning('[EstimateGeneration OCR] Yandex OCR returned error', [
            'provider' => self::PROVIDER,
            'mode' => $mode,
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

    /**
     * @param array<string, mixed> $operation
     */
    private function operationId(array $operation): ?string
    {
        $id = Arr::get($operation, 'id', Arr::get($operation, 'operation.id'));

        return is_string($id) && $id !== '' ? $id : null;
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
        $pages = [];

        foreach ($this->recognitionEntries($payload) as $entry) {
            $result = Arr::get($entry, 'result', $entry);
            $annotation = Arr::get($result, 'textAnnotation', Arr::get($result, 'text_annotation', []));

            if (!is_array($annotation)) {
                $pages[] = new OcrPageResult(pageNumber: count($pages) + 1, text: '', rawPayload: $entry);

                continue;
            }

            $annotationPages = Arr::get($annotation, 'pages');

            if (is_array($annotationPages) && $annotationPages !== []) {
                foreach ($annotationPages as $page) {
                    $pages[] = $this->pageFromPayload(
                        is_array($page) ? $page : [],
                        count($pages) + 1
                    );
                }

                continue;
            }

            if (is_array($result) && Arr::has($result, 'page') && !Arr::has($annotation, 'pageNumber')) {
                $annotation['pageNumber'] = $this->zeroBasedPageNumber(Arr::get($result, 'page'), count($pages) + 1);
            }

            $pages[] = $this->pageFromPayload($annotation, count($pages) + 1);
        }

        return $pages !== []
            ? $pages
            : [new OcrPageResult(pageNumber: 1, text: '', rawPayload: $payload)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function recognitionEntries(array $payload): array
    {
        $results = Arr::get($payload, 'results');

        if (is_array($results) && $results !== []) {
            return array_values(array_filter($results, 'is_array'));
        }

        return [$payload];
    }

    private function zeroBasedPageNumber(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? max(1, (int) $value + 1) : $fallback;
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
            $alternatives = Arr::get($line, 'alternatives', []);
            $alternative = is_array($alternatives)
                ? Arr::first($alternatives, static fn (mixed $value): bool => is_array($value))
                : [];
            $alternative = is_array($alternative) ? $alternative : [];
            $words = Arr::get($line, 'words', Arr::get($alternative, 'words', []));

            return [
                'text' => (string) Arr::get($line, 'text', Arr::get($alternative, 'text', '')),
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
