<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\ConstructionJournal;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class JournalEntryQueryService
{
    private const ROWS = ['workVolumes' => 'journal_work_volumes', 'materials' => 'journal_materials', 'equipment' => 'journal_equipment', 'workers' => 'journal_workers'];

    public function __construct(private readonly JournalRowContractService $contracts) {}

    public function query(ConstructionJournal $journal, array $filters): Builder
    {
        $contractId = $this->contractId($filters);
        $unassigned = $this->unassigned($filters);
        if ($contractId !== null && ($unassigned || ! $this->contracts->contracts($journal)->whereKey($contractId)->exists())) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_contract'));
        }
        $query = $journal->entries()->getQuery();
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        foreach (['date' => '=', 'date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
            if (! empty($filters[$key])) {
                $query->whereDate('entry_date', $operator, $filters[$key]);
            }
        }
        if ($contractId !== null || $unassigned) {
            $query->where(function (Builder $query) use ($contractId): void {
                foreach (self::ROWS as $relation => $table) {
                    $query->orWhereHas($relation, static function (Builder $rows) use ($contractId): void {
                        $contractId === null ? $rows->whereNull('contract_id') : $rows->where('contract_id', $contractId);
                    });
                    $array = "CASE WHEN jsonb_typeof(source_entry_snapshot->'$relation') = 'array' THEN source_entry_snapshot->'$relation' ELSE '[]'::jsonb END";
                    $condition = $contractId === null ? "element->>'contract_id' IS NULL" : "element->>'contract_id' = ?";
                    $query->orWhereRaw("EXISTS (SELECT 1 FROM jsonb_array_elements($array) AS element WHERE $condition)", $contractId === null ? [] : [(string) $contractId]);
                }
            });
        }

        return $query;
    }

    public function filterPayload(array $payload, array $filters): array
    {
        $contractId = $this->contractId($filters);
        if ($contractId === null && ! $this->unassigned($filters)) {
            return $payload;
        }
        foreach (self::ROWS as $relation => $table) {
            $payload[$relation] = array_values(array_filter($payload[$relation] ?? [], static function (array $row) use ($contractId): bool {
                $rowId = ($row['contract_id'] ?? null) === null ? null : (int) $row['contract_id'];

                return $rowId === $contractId;
            }));
        }
        $payload['rows_filtered'] = true;

        return $payload;
    }

    public function summary(ConstructionJournal $journal, array $filters): array
    {
        $query = $this->query($journal, $filters);
        $summary = [
            'total_entries' => (clone $query)->count(),
            'approved_entries' => (clone $query)->where('status', 'approved')->count(),
            'submitted_entries' => (clone $query)->where('status', 'submitted')->count(),
            'rejected_entries' => (clone $query)->where('status', 'rejected')->count(),
            'total_rows' => 0,
            'unassigned_rows' => 0,
        ];
        $contractId = $this->contractId($filters);
        $unassigned = $this->unassigned($filters);
        foreach (self::ROWS as $relation => $table) {
            $rows = DB::table($table)->whereIn('journal_entry_id', (clone $query)->select('construction_journal_entries.id'));
            if ($contractId !== null) {
                $rows->where('contract_id', $contractId);
            } elseif ($unassigned) {
                $rows->whereNull('contract_id');
            }
            $summary['total_rows'] += (clone $rows)->count();
            $summary['unassigned_rows'] += (clone $rows)->whereNull('contract_id')->count();
            $array = "CASE WHEN jsonb_typeof(source_entry_snapshot->'$relation') = 'array' THEN source_entry_snapshot->'$relation' ELSE '[]'::jsonb END";
            $condition = $contractId !== null ? "element->>'contract_id' = ?" : ($unassigned ? "element->>'contract_id' IS NULL" : 'TRUE');
            $bindings = $contractId !== null ? [(string) $contractId] : [];
            $snapshot = (clone $query)->whereNotNull('source_entry_id')->reorder()->toBase()->selectRaw(
                "COALESCE(SUM((SELECT count(*) FROM jsonb_array_elements($array) AS element WHERE $condition)), 0) AS matching_rows, COALESCE(SUM((SELECT count(*) FROM jsonb_array_elements($array) AS element WHERE $condition AND element->>'contract_id' IS NULL)), 0) AS unassigned_rows",
                [...$bindings, ...$bindings],
            )->first();
            $summary['total_rows'] += (int) ($snapshot?->matching_rows ?? 0);
            $summary['unassigned_rows'] += (int) ($snapshot?->unassigned_rows ?? 0);
        }

        return $summary;
    }

    private function contractId(array $filters): ?int
    {
        if (! isset($filters['contract_id']) || $filters['contract_id'] === '') {
            return null;
        }
        $id = filter_var($filters['contract_id'], FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_contract'));
        }

        return $id;
    }

    private function unassigned(array $filters): bool
    {
        $value = filter_var($filters['unassigned_only'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value === null) {
            throw new DomainException(trans_message('construction_journal.errors.contract_assignment_invalid'));
        }

        return $value;
    }
}
