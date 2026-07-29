<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Infrastructure\Cursors;

use App\BusinessModules\Core\Reporting\Application\Contracts\Execution\ReportExecutionClock;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportCursor;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWindowSort;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Throwable;

final readonly class SignedReportCursorCodec
{
    private const PAYLOAD_FIELDS = [
        'definition_hash',
        'expires_at',
        'issued_at',
        'key_id',
        'last_sort_value',
        'last_stable_row_key',
        'organization_id',
        'query_hash',
        'report_code',
        'run_id',
        'snapshot_id',
        'sort_direction',
        'sort_field',
        'source_hash',
    ];

    private array $keys;

    public function __construct(
        array $keys,
        private string $activeKeyId,
        private ReportExecutionClock $clock,
    ) {
        $validated = [];
        foreach ($keys as $keyId => $secret) {
            if (! is_string($keyId)
                || preg_match('/^[a-z][a-z0-9_.:-]{2,127}$/D', $keyId) !== 1
                || ! is_string($secret)
                || strlen($secret) < 32) {
                throw new InvalidArgumentException('report_cursor_keys_invalid');
            }
            $validated[$keyId] = $secret;
        }
        if ($validated === [] || ! isset($validated[$activeKeyId])) {
            throw new InvalidArgumentException('report_cursor_keys_invalid');
        }

        $this->keys = $validated;
    }

    public function encode(
        int $organizationId,
        string $reportCode,
        string $runId,
        ReportSnapshotRef $snapshot,
        Sha256Hash $queryHash,
        ReportWindowSort $sort,
        string|int|float|bool|null $lastSortValue,
        string $lastStableRowKey,
        DateTimeImmutable $expiresAt,
    ): string {
        $issuedAt = $this->clock->now();
        if ($organizationId < 1
            || $organizationId !== $snapshot->scope->organizationId
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $runId) !== 1
            || trim($lastStableRowKey) === ''
            || (is_float($lastSortValue) && ! is_finite($lastSortValue))
            || $expiresAt <= $issuedAt) {
            throw $this->invalid();
        }

        try {
            $payload = CanonicalJson::encode([
                'definition_hash' => $snapshot->definitionHash->value,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'issued_at' => $issuedAt->format(DATE_ATOM),
                'key_id' => $this->activeKeyId,
                'last_sort_value' => $lastSortValue,
                'last_stable_row_key' => $lastStableRowKey,
                'organization_id' => $organizationId,
                'query_hash' => $queryHash->value,
                'report_code' => $reportCode,
                'run_id' => $runId,
                'snapshot_id' => $snapshot->id,
                'sort_direction' => $sort->direction->value,
                'sort_field' => $sort->field,
                'source_hash' => $snapshot->sourceHash->value,
            ]);
        } catch (Throwable $exception) {
            throw $this->invalid($exception);
        }
        $encodedPayload = self::base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, $this->keys[$this->activeKeyId], true);

        return $encodedPayload.'.'.self::base64UrlEncode($signature);
    }

    public function decode(
        string $token,
        int $organizationId,
        string $reportCode,
        string $runId,
        ReportSnapshotRef $snapshot,
        Sha256Hash $queryHash,
        ReportWindowSort $sort,
    ): ReportCursor {
        try {
            [$encodedPayload, $encodedSignature] = $this->segments($token);
            $payload = $this->payload($encodedPayload);
            $keyId = $payload['key_id'];
            $secret = $this->keys[$keyId] ?? null;
            if (! is_string($secret)) {
                throw new InvalidArgumentException('report_cursor_key_missing');
            }

            $signature = self::base64UrlDecode($encodedSignature);
            $expected = hash_hmac('sha256', $encodedPayload, $secret, true);
            if (strlen($signature) !== strlen($expected) || ! hash_equals($expected, $signature)) {
                throw new InvalidArgumentException('report_cursor_signature_invalid');
            }

            $issuedAt = $this->timestamp($payload['issued_at']);
            $expiresAt = $this->timestamp($payload['expires_at']);
            $now = $this->clock->now();
            if ($issuedAt > $now || $expiresAt <= $issuedAt || $expiresAt <= $now) {
                throw new InvalidArgumentException('report_cursor_expired');
            }

            if ($payload['organization_id'] !== $organizationId
                || $organizationId !== $snapshot->scope->organizationId
                || ! hash_equals($payload['report_code'], $reportCode)
                || ! hash_equals($payload['run_id'], $runId)
                || ! hash_equals($payload['snapshot_id'], $snapshot->id)
                || ! hash_equals($payload['definition_hash'], $snapshot->definitionHash->value)
                || ! hash_equals($payload['query_hash'], $queryHash->value)
                || ! hash_equals($payload['source_hash'], $snapshot->sourceHash->value)
                || ! hash_equals($payload['sort_field'], $sort->field)
                || ! hash_equals($payload['sort_direction'], $sort->direction->value)) {
                throw new InvalidArgumentException('report_cursor_identity_mismatch');
            }

            return new ReportCursor(
                $token,
                $runId,
                $queryHash,
                $snapshot->sourceHash,
                $sort,
                $expiresAt,
            );
        } catch (Throwable $exception) {
            if ($exception instanceof ReportContractException) {
                throw $exception;
            }

            throw $this->invalid($exception);
        }
    }

    /** @return array{string,string} */
    private function segments(string $token): array
    {
        $segments = explode('.', $token);
        if (strlen($token) > 2048 || count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new InvalidArgumentException('report_cursor_token_invalid');
        }

        return [$segments[0], $segments[1]];
    }

    private function payload(string $encoded): array
    {
        try {
            $json = self::base64UrlDecode($encoded);
            $payload = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('report_cursor_payload_invalid', 0, $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvalidArgumentException('report_cursor_payload_invalid');
        }
        if (CanonicalJson::encode($payload) !== $json) {
            throw new InvalidArgumentException('report_cursor_payload_invalid');
        }

        $fields = array_keys($payload);
        sort($fields, SORT_STRING);
        if ($fields !== self::PAYLOAD_FIELDS
            || ! is_string($payload['definition_hash'])
            || ! is_string($payload['expires_at'])
            || ! is_string($payload['issued_at'])
            || ! is_string($payload['key_id'])
            || ! self::isScalarOrNull($payload['last_sort_value'])
            || ! is_string($payload['last_stable_row_key'])
            || trim($payload['last_stable_row_key']) === ''
            || ! is_int($payload['organization_id'])
            || $payload['organization_id'] < 1
            || ! is_string($payload['query_hash'])
            || ! is_string($payload['report_code'])
            || ! is_string($payload['run_id'])
            || ! is_string($payload['snapshot_id'])
            || ! is_string($payload['sort_direction'])
            || ! is_string($payload['sort_field'])
            || ! is_string($payload['source_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['definition_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['query_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['source_hash']) !== 1) {
            throw new InvalidArgumentException('report_cursor_payload_invalid');
        }

        return $payload;
    }

    private function timestamp(string $value): DateTimeImmutable
    {
        $timestamp = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $timestamp instanceof DateTimeImmutable
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format(DATE_ATOM) !== $value) {
            throw new InvalidArgumentException('report_cursor_timestamp_invalid');
        }

        return $timestamp;
    }

    private static function isScalarOrNull(mixed $value): bool
    {
        return $value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value));
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw new InvalidArgumentException('report_cursor_base64_invalid');
        }

        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (! is_string($decoded) || self::base64UrlEncode($decoded) !== $value) {
            throw new InvalidArgumentException('report_cursor_base64_invalid');
        }

        return $decoded;
    }

    private function invalid(?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(
            ReportErrorCode::REPORT_CURSOR_INVALID,
            ['fields' => ['cursor']],
            $previous,
        );
    }
}
