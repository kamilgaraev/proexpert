<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Providers;

use Illuminate\Http\Client\Response;
use JsonException;

final readonly class TimewebProviderErrorInspector
{
    private const SENSITIVE_BODY_KEYS = [
        'request_dump', 'request', 'messages', 'prompt', 'input', 'user_text', 'image_url',
        'authorization', 'api_key',
    ];

    private const MAX_TYPED_VALUE_BYTES = 120;

    private const MAX_MESSAGE_BYTES = 320;

    public function inspect(
        Response $response,
        int $httpStatus,
        string $model,
        string $endpointKind,
        string $promptContractFingerprint,
        string $payloadShapeFingerprint,
        int $maxBytes,
    ): ProviderErrorDiagnostics {
        $limit = max(256, min(65_536, $maxBytes));
        [$body, $truncated] = $this->readBounded($response, $limit);
        $bodyFingerprint = 'sha256:'.hash('sha256', $body."\0".($truncated ? 'truncated' : 'complete'));
        $contentType = $this->contentType($response);
        $error = $truncated ? null : $this->openAiError($body);
        $type = $this->typedValue($error['type'] ?? null);
        $code = $this->typedValue($error['code'] ?? null);
        $param = $this->parameter($error['param'] ?? null);
        $envelopeKind = $error === null ? 'unknown' : 'openai_error';
        $bodyShapeFingerprint = $this->bodyShapeFingerprint($body, $truncated, $contentType);
        $diagnosticFingerprint = 'sha256:'.hash('sha256', json_encode([
            'provider' => TimewebVisionProvider::PROVIDER,
            'http_status' => $httpStatus,
            'error_type' => $type,
            'error_code' => $code,
            'error_param' => $param,
            'error_identity' => $error === null
                ? $bodyShapeFingerprint
                : ['type' => $type, 'code' => $code, 'param' => $param],
            'model' => $model,
            'endpoint_kind' => $endpointKind,
            'prompt_contract_fingerprint' => $promptContractFingerprint,
            'payload_shape_fingerprint' => $payloadShapeFingerprint,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $payload = [
            'provider_http_status' => $httpStatus,
            'envelope_kind' => $envelopeKind,
            'response_content_type' => $contentType,
            'body_fingerprint' => $bodyFingerprint,
            'body_shape_fingerprint' => $bodyShapeFingerprint,
            'body_truncated' => $truncated,
            'model' => $model,
            'endpoint_kind' => $endpointKind,
            'prompt_contract_fingerprint' => $promptContractFingerprint,
            'payload_shape_fingerprint' => $payloadShapeFingerprint,
            'diagnostic_fingerprint' => $diagnosticFingerprint,
        ];
        if ($type !== null) {
            $payload['error_type'] = $type;
        }
        if ($code !== null) {
            $payload['error_code'] = $code;
        }
        if ($param !== null) {
            $payload['error_param'] = $param;
        }
        $payload['diagnostic_summary'] = $this->diagnosticSummary($httpStatus, $envelopeKind, $type, $code, $param);
        if ($error === null) {
            $payload['redacted_preview'] = $this->safeText($body, $limit);
        }

        return new ProviderErrorDiagnostics($payload, array_filter([
            'provider_http_status' => $httpStatus,
            'provider_error_type' => $type,
            'provider_error_code' => $code,
            'provider_error_param' => $param,
            'body_fingerprint' => $bodyFingerprint,
            'body_shape_fingerprint' => $bodyShapeFingerprint,
            'endpoint_kind' => $endpointKind,
            'prompt_contract_fingerprint' => $promptContractFingerprint,
            'payload_shape_fingerprint' => $payloadShapeFingerprint,
            'diagnostic_fingerprint' => $diagnosticFingerprint,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @return array{string, bool} */
    private function readBounded(Response $response, int $maxBytes): array
    {
        $resource = $response->resource();
        $body = '';
        try {
            while (! feof($resource) && strlen($body) <= $maxBytes) {
                $remaining = $maxBytes + 1 - strlen($body);
                $chunk = fread($resource, min(16_384, $remaining));
                if ($chunk === false || ($chunk === '' && ! feof($resource))) {
                    break;
                }
                $body .= $chunk;
            }
        } finally {
            fclose($resource);
        }

        $truncated = strlen($body) > $maxBytes;

        return [substr($body, 0, $maxBytes), $truncated];
    }

    /** @return array<string, mixed>|null */
    private function openAiError(string $body): ?array
    {
        try {
            $decoded = json_decode($body, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            return null;
        }
        $error = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        return is_array($error) ? $error : null;
    }

    private function typedValue(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::MAX_TYPED_VALUE_BYTES
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9._-]*\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function parameter(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::MAX_TYPED_VALUE_BYTES
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_.\[\]-]*\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function contentType(Response $response): string
    {
        $value = strtolower(trim(explode(';', $response->header('Content-Type'), 2)[0]));

        return preg_match('/\A[a-z0-9.+-]+\/[a-z0-9.+-]+\z/', $value) === 1 ? $value : 'unknown';
    }

    private function diagnosticSummary(
        int $httpStatus,
        string $envelopeKind,
        ?string $type,
        ?string $code,
        ?string $param,
    ): string {
        return implode('; ', array_filter([
            'provider_http_status='.$httpStatus,
            'envelope='.$envelopeKind,
            $type === null ? null : 'type='.$type,
            $code === null ? null : 'code='.$code,
            $param === null ? null : 'param='.$param,
        ], static fn (?string $value): bool => $value !== null));
    }

    private function bodyShapeFingerprint(string $body, bool $truncated, string $contentType): string
    {
        $shape = null;
        if (! $truncated) {
            try {
                $shape = $this->valueShape(json_decode($body, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING));
            } catch (JsonException) {
            }
        }

        return 'sha256:'.hash('sha256', json_encode([
            'content_type' => $contentType,
            'body_shape' => $shape ?? ($truncated ? 'truncated' : 'malformed'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function valueShape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }
        if (array_is_list($value)) {
            return ['list' => $value === [] ? 'empty' : $this->valueShape($value[0])];
        }

        $shape = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $shape[(string) $key] = $this->valueShape($value[$key]);
        }

        return $shape;
    }

    private function safeText(string $value, int $maxBytes = self::MAX_MESSAGE_BYTES): string
    {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        try {
            $decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            if (is_array($decoded)) {
                $value = json_encode($this->redactStructuredValue($decoded), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        } catch (JsonException) {
            if (preg_match('/"(?:'.implode('|', self::SENSITIVE_BODY_KEYS).')"\s*:\s*[\[{]/i', $value) === 1) {
                return '[redacted-structured-content]';
            }
        }
        $patterns = [
            '~("(?:'.implode('|', self::SENSITIVE_BODY_KEYS).')"\s*:\s*)"(?:\\\\.|[^"])*(?:"|$)~is' => '$1"[redacted]"',
            '~data:[^,\s]+,[A-Za-z0-9+/=_-]+~i' => '[redacted-data]',
            '~https?://[^\s"\'<>]+~i' => '[redacted-url]',
            '~(?:authorization\s*[:=]\s*)?bearer\s+[A-Za-z0-9._+/=-]+~i' => 'Authorization: [redacted]',
            '~\b(?:sk|gh[pousr]|akia)[-_][A-Za-z0-9_-]{8,}\b~i' => '[redacted-secret]',
            '~[?&](?:x-amz-[a-z-]+|signature|token|key|secret)=[^&\s"\']+~i' => '[redacted-query]',
            '~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i' => '[redacted-email]',
            '~(?<!\d)(?:\+?\d[\s().-]*){10,15}(?!\d)~' => '[redacted-phone]',
            '~[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+~u' => ' ',
        ];
        $redacted = preg_replace(array_keys($patterns), array_values($patterns), $value) ?? '';
        $redacted = preg_replace('/\s+/u', ' ', $redacted) ?? '';

        return trim(mb_strcut($redacted, 0, max(1, $maxBytes), 'UTF-8'));
    }

    private function redactStructuredValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::SENSITIVE_BODY_KEYS, true)) {
            return '[redacted]';
        }
        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = $this->redactStructuredValue(
                $childValue,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $redacted;
    }
}
