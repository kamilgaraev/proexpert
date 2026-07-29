<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Support;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSnapshotRef;
use DateTimeInterface;
use DomainException;

final readonly class OwnerSnapshotIdentityGuard
{
    public function assert(
        object $record,
        ReportExecutionContext $context,
        ReportSnapshotRef $snapshot,
        array $versionColumns = [],
    ): void {
        if ($snapshot->scope->canonicalIdentity() !== $context->scope->canonicalIdentity()) {
            throw new DomainException('report_snapshot_scope_mismatch');
        }

        $scopeHash = hash('sha256', CanonicalJson::encode($context->scope->canonicalIdentity()));
        $expected = [
            'organization_id' => $context->scope->organizationId,
            'scope_hash' => $scopeHash,
            'query_hash' => $this->watermark($snapshot, 'query_hash'),
            'definition_hash' => $snapshot->definitionHash->value,
            'formula_version' => $snapshot->formulaVersion,
            'source_hash' => $snapshot->sourceHash->value,
            'as_of' => $this->watermark($snapshot, 'as_of'),
        ];
        foreach ($versionColumns as $column => $watermark) {
            $expected[$column] = $this->watermark($snapshot, $watermark);
        }

        foreach ($expected as $column => $value) {
            $actual = $record->{$column} ?? null;
            if ($actual instanceof DateTimeInterface) {
                $actual = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    ? $actual->format('Y-m-d')
                    : $actual->format(DATE_ATOM);
            } elseif (is_object($actual) && method_exists($actual, 'toDateTimeImmutable')) {
                $date = $actual->toDateTimeImmutable();
                $actual = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1
                    ? $date->format('Y-m-d')
                    : $date->format(DATE_ATOM);
            } elseif (is_int($value)) {
                $actual = $actual === null ? 0 : (is_numeric($actual) ? (int) $actual : $actual);
            } else {
                $actual = (string) $actual;
            }

            if ($actual !== $value) {
                throw new DomainException('report_snapshot_identity_mismatch');
            }
        }
    }

    private function watermark(ReportSnapshotRef $snapshot, string $key): string|int
    {
        $value = $snapshot->watermarks[$key] ?? null;
        if (! is_string($value) && ! is_int($value)) {
            throw new DomainException('report_snapshot_identity_watermark_missing');
        }

        return $value;
    }
}
