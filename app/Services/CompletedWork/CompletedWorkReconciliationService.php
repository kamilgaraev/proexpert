<?php

declare(strict_types=1);

namespace App\Services\CompletedWork;

use InvalidArgumentException;

final class CompletedWorkReconciliationService
{
    public function analyze(iterable $records, int $organizationId, int $projectId): array
    {
        if ($organizationId <= 0 || $projectId <= 0) {
            throw new InvalidArgumentException('completed_work_reconciliation_scope_invalid');
        }

        $rows = [];
        $journalVolumes = [];

        foreach ($records as $record) {
            $id = (int) ($record['id'] ?? 0);
            if ($id <= 0 || isset($rows[$id])) {
                throw new InvalidArgumentException('completed_work_reconciliation_identity_invalid');
            }
            if ((int) ($record['organization_id'] ?? 0) !== $organizationId
                || (int) ($record['project_id'] ?? 0) !== $projectId
            ) {
                throw new InvalidArgumentException('completed_work_reconciliation_scope_mismatch');
            }

            $rows[$id] = $record;
            $journalVolumeId = (int) ($record['journal_work_volume_id'] ?? 0);
            if ($journalVolumeId > 0) {
                $journalVolumes[$journalVolumeId][] = $id;
            }
        }

        ksort($rows);
        $report = [];

        foreach ($rows as $id => $record) {
            $quantity = $this->normalize($record['quantity'] ?? null);
            $completed = $this->normalize($record['completed_quantity'] ?? null);
            $issues = [];

            if ($quantity === null
                || (($record['completed_quantity'] ?? null) !== null && $completed === null)
            ) {
                $issues[] = 'invalid_quantity';
            } elseif (($record['completed_quantity'] ?? null) === null) {
                $issues[] = 'missing_completed_quantity';
            } elseif ($quantity !== $completed) {
                $issues[] = 'quantity_conflict';
            }

            $duplicates = $journalVolumes[(int) ($record['journal_work_volume_id'] ?? 0)] ?? [];
            sort($duplicates);
            if (count($duplicates) > 1) {
                $issues[] = 'duplicate_journal_volume';
            }

            $acts = $record['acts'] ?? [];
            $protected = false;
            foreach ($acts as $act) {
                if (($act['is_approved'] ?? false)
                    || in_array($act['status'] ?? null, ['approved', 'signed'], true)
                ) {
                    $protected = true;
                }
            }

            $report[] = [
                'work_id' => $id,
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'source' => [
                    'quantity' => $record['quantity'] ?? null,
                    'completed_quantity' => $record['completed_quantity'] ?? null,
                    'status' => $record['status'] ?? null,
                    'journal_entry_id' => $record['journal_entry_id'] ?? null,
                    'journal_work_volume_id' => $record['journal_work_volume_id'] ?? null,
                    'total_amount' => $record['total_amount'] ?? null,
                ],
                'acts' => $acts,
                'issues' => $issues,
                'duplicate_work_ids' => count($duplicates) > 1 ? $duplicates : [],
                'protected_history' => $protected,
                'requires_manual_review' => $issues !== [],
                'resolved_quantity' => $issues === [] ? $completed : null,
            ];
        }

        return $report;
    }

    private function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        if (preg_match('/^(\d+)(?:\.(\d{1,4}))?$/D', trim((string) $value), $matches) !== 1) {
            return null;
        }

        $whole = ltrim($matches[1], '0');
        if (strlen($whole) > 14) {
            return null;
        }

        return ($whole === '' ? '0' : $whole).'.'.str_pad($matches[2] ?? '', 4, '0');
    }
}
