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
    private const MAX_TOKEN_BYTES = 2048;

    private const MAX_ROW_KEY_BYTES = 256;

    private const CURSOR_PAYLOAD_FIELDS = [
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

    private const DRILL_DOWN_PAYLOAD_FIELDS = [
        'column_id',
        'definition_hash',
        'expires_at',
        'issued_at',
        'key_id',
        'organization_id',
        'query_hash',
        'report_code',
        'row_key',
        'run_id',
        'snapshot_id',
        'source_hash',
        'token_type',
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
            || ! self::isCanonicalRowKey($lastStableRowKey)
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
            throw $this->invalid('cursor', $exception);
        }
        return $this->sign($payload, 'cursor');
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
            $payload = $this->verifiedPayload($token, self::CURSOR_PAYLOAD_FIELDS);

            $expiresAt = $this->assertLifetime($payload);

            if (! self::isScalarOrNull($payload['last_sort_value'])
                || ! is_string($payload['last_stable_row_key'])
                || ! is_string($payload['sort_direction'])
                || ! is_string($payload['sort_field'])
                || $payload['organization_id'] !== $organizationId
                || $organizationId !== $snapshot->scope->organizationId
                || ! hash_equals($payload['report_code'], $reportCode)
                || ! hash_equals($payload['run_id'], $runId)
                || ! hash_equals($payload['snapshot_id'], $snapshot->id)
                || ! hash_equals($payload['definition_hash'], $snapshot->definitionHash->value)
                || ! hash_equals($payload['query_hash'], $queryHash->value)
                || ! hash_equals($payload['source_hash'], $snapshot->sourceHash->value)
                || ! hash_equals($payload['sort_field'], $sort->field)
                || ! hash_equals($payload['sort_direction'], $sort->direction->value)
                || ! self::isCanonicalRowKey($payload['last_stable_row_key'])) {
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

            throw $this->invalid('cursor', $exception);
        }
    }

    public function encodeDrillDownCell(
        int $organizationId,
        string $reportCode,
        string $runId,
        ReportSnapshotRef $snapshot,
        Sha256Hash $queryHash,
        string $rowKey,
        string $columnId,
        DateTimeImmutable $expiresAt,
    ): string {
        $issuedAt = $this->clock->now();
        if ($organizationId < 1
            || $organizationId !== $snapshot->scope->organizationId
            || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $reportCode) !== 1
            || preg_match('/^[0-9ABCDEFGHJKMNPQRSTVWXYZ]{26}$/D', $runId) !== 1
            || ! self::isCanonicalRowKey($rowKey)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $columnId) !== 1
            || $expiresAt <= $issuedAt) {
            throw $this->invalid('token');
        }

        try {
            $payload = CanonicalJson::encode([
                'column_id' => $columnId,
                'definition_hash' => $snapshot->definitionHash->value,
                'expires_at' => $expiresAt->format(DATE_ATOM),
                'issued_at' => $issuedAt->format(DATE_ATOM),
                'key_id' => $this->activeKeyId,
                'organization_id' => $organizationId,
                'query_hash' => $queryHash->value,
                'report_code' => $reportCode,
                'row_key' => $rowKey,
                'run_id' => $runId,
                'snapshot_id' => $snapshot->id,
                'source_hash' => $snapshot->sourceHash->value,
                'token_type' => 'report_drill_down_cell',
            ]);
        } catch (Throwable $exception) {
            throw $this->invalid('token', $exception);
        }

        return $this->sign($payload, 'token');
    }

    /** @return array{row_key:string,column_id:string} */
    public function decodeDrillDownCell(
        string $token,
        int $organizationId,
        string $reportCode,
        string $runId,
        ReportSnapshotRef $snapshot,
        Sha256Hash $queryHash,
    ): array {
        try {
            $payload = $this->verifiedPayload($token, self::DRILL_DOWN_PAYLOAD_FIELDS);
            $this->assertLifetime($payload);
            if (! is_string($payload['token_type'])
                || ! is_string($payload['row_key'])
                || ! is_string($payload['column_id'])
                || $payload['token_type'] !== 'report_drill_down_cell'
                || $payload['organization_id'] !== $organizationId
                || $organizationId !== $snapshot->scope->organizationId
                || ! hash_equals($payload['report_code'], $reportCode)
                || ! hash_equals($payload['run_id'], $runId)
                || ! hash_equals($payload['snapshot_id'], $snapshot->id)
                || ! hash_equals($payload['definition_hash'], $snapshot->definitionHash->value)
                || ! hash_equals($payload['query_hash'], $queryHash->value)
                || ! hash_equals($payload['source_hash'], $snapshot->sourceHash->value)
                || ! self::isCanonicalRowKey($payload['row_key'])
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $payload['column_id']) !== 1) {
                throw new InvalidArgumentException('report_drill_down_cell_identity_mismatch');
            }

            return [
                'row_key' => $payload['row_key'],
                'column_id' => $payload['column_id'],
            ];
        } catch (Throwable $exception) {
            if ($exception instanceof ReportContractException) {
                throw $exception;
            }

            throw $this->invalid('token', $exception);
        }
    }

    /** @return array{string,string} */
    private function segments(string $token): array
    {
        $segments = explode('.', $token);
        if (strlen($token) > self::MAX_TOKEN_BYTES || count($segments) !== 2 || $segments[0] === '' || $segments[1] === '') {
            throw new InvalidArgumentException('report_cursor_token_invalid');
        }

        return [$segments[0], $segments[1]];
    }

    private function payload(string $encoded, array $expectedFields): array
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
        if ($fields !== $expectedFields
            || ! is_string($payload['definition_hash'])
            || ! is_string($payload['expires_at'])
            || ! is_string($payload['issued_at'])
            || ! is_string($payload['key_id'])
            || ! is_int($payload['organization_id'])
            || $payload['organization_id'] < 1
            || ! is_string($payload['query_hash'])
            || ! is_string($payload['report_code'])
            || ! is_string($payload['run_id'])
            || ! is_string($payload['snapshot_id'])
            || ! is_string($payload['source_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['definition_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['query_hash']) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $payload['source_hash']) !== 1) {
            throw new InvalidArgumentException('report_cursor_payload_invalid');
        }

        return $payload;
    }

    private function sign(string $payload, string $safeField): string
    {
        $encodedPayload = self::base64UrlEncode($payload);
        if (strlen($encodedPayload) + 44 > self::MAX_TOKEN_BYTES) {
            throw $this->invalid($safeField);
        }

        $signature = hash_hmac('sha256', $encodedPayload, $this->keys[$this->activeKeyId], true);
        $token = $encodedPayload.'.'.self::base64UrlEncode($signature);
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw $this->invalid($safeField);
        }

        return $token;
    }

    private function verifiedPayload(string $token, array $expectedFields): array
    {
        [$encodedPayload, $encodedSignature] = $this->segments($token);
        $payload = $this->payload($encodedPayload, $expectedFields);
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

        return $payload;
    }

    private function assertLifetime(array $payload): DateTimeImmutable
    {
        $issuedAt = $this->timestamp($payload['issued_at']);
        $expiresAt = $this->timestamp($payload['expires_at']);
        $now = $this->clock->now();
        if ($issuedAt > $now || $expiresAt <= $issuedAt || $expiresAt <= $now) {
            throw new InvalidArgumentException('report_cursor_expired');
        }

        return $expiresAt;
    }

    private static function isCanonicalRowKey(mixed $rowKey): bool
    {
        return is_string($rowKey)
            && $rowKey !== ''
            && $rowKey === trim($rowKey)
            && strlen($rowKey) <= self::MAX_ROW_KEY_BYTES;
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

    private function invalid(string $safeField = 'cursor', ?Throwable $previous = null): ReportContractException
    {
        return ReportContractException::fromCode(
            ReportErrorCode::REPORT_CURSOR_INVALID,
            ['fields' => [$safeField]],
            $previous,
        );
    }
}
