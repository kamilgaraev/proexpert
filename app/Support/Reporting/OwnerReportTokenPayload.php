<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use DomainException;
use JsonException;

final readonly class OwnerReportTokenPayload
{
    public function cursor(string $token, ReportSnapshotRef $snapshot): array
    {
        $payload = $this->decode($token);
        foreach (['last_sort_value', 'last_stable_row_key', 'snapshot_id', 'organization_id'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new DomainException('Report cursor position is incomplete.');
            }
        }
        $this->assertSnapshot($payload, $snapshot);
        if (! is_string($payload['last_stable_row_key']) || trim($payload['last_stable_row_key']) === '') {
            throw new DomainException('Report cursor row key is invalid.');
        }

        return [
            'sort_value' => $payload['last_sort_value'],
            'row_key' => $payload['last_stable_row_key'],
        ];
    }

    public function drillDownRowKey(string $token, ReportSnapshotRef $snapshot): string
    {
        $payload = $this->decode($token);
        foreach (['row_key', 'snapshot_id', 'organization_id', 'token_type'] as $field) {
            if (! array_key_exists($field, $payload)) {
                throw new DomainException('Report drill-down token is incomplete.');
            }
        }
        $this->assertSnapshot($payload, $snapshot);
        if ($payload['token_type'] !== 'report_drill_down_cell'
            || ! is_string($payload['row_key'])
            || trim($payload['row_key']) === '') {
            throw new DomainException('Report drill-down row key is invalid.');
        }

        return $payload['row_key'];
    }

    private function decode(string $token): array
    {
        $encoded = explode('.', $token, 2)[0] ?? '';
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $encoded) !== 1) {
            throw new DomainException('Report token payload is invalid.');
        }
        $decoded = base64_decode(strtr($encoded, '-_', '+/').str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        if (! is_string($decoded)) {
            throw new DomainException('Report token payload is invalid.');
        }

        try {
            $payload = json_decode($decoded, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('Report token payload is invalid.', 0, $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new DomainException('Report token payload is invalid.');
        }

        return $payload;
    }

    private function assertSnapshot(array $payload, ReportSnapshotRef $snapshot): void
    {
        if ($payload['organization_id'] !== $snapshot->scope->organizationId
            || ! is_string($payload['snapshot_id'])
            || ! hash_equals($payload['snapshot_id'], $snapshot->id)) {
            throw new DomainException('Report token snapshot identity is invalid.');
        }
    }
}
