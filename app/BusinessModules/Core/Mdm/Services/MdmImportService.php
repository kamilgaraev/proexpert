<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmImportBatch;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MdmImportService
{
    public function __construct(
        private readonly MdmQualityService $qualityService,
        private readonly MdmEntityRegistry $registry,
        private readonly MdmRecordService $recordService
    ) {}

    public function preview(int $organizationId, string $entityType, array $rows, ?int $userId = null, string $source = 'manual'): MdmImportBatch
    {
        $issues = [];
        $accepted = 0;

        foreach (array_values($rows) as $index => $row) {
            $data = is_array($row) ? $this->registry->sanitizeValues($entityType, $row) : [];
            $quality = $this->qualityService->evaluate($entityType, $data, $organizationId);
            $referenceErrors = $this->referenceErrors($organizationId, $entityType, $data);

            if ($referenceErrors !== []) {
                $referenceIssues = [];
                foreach ($referenceErrors as $field => $messages) {
                    foreach ($messages as $message) {
                        $referenceIssues[] = ['code' => 'reference_not_found', 'field' => $field, 'message' => $message];
                    }
                }
                $issues[] = [
                    'row' => $index + 1,
                    'score' => $quality['score'],
                    'issues' => [...$quality['issues'], ...$referenceIssues],
                ];

                continue;
            }

            if ($quality['score'] >= 70) {
                if (! $this->registry->supportsCreate($entityType) && $this->findRecordByQuality($organizationId, $entityType, $quality) === null) {
                    $issues[] = [
                        'row' => $index + 1,
                        'score' => $quality['score'],
                        'issues' => [
                            [
                                'code' => 'existing_record_required',
                                'field' => 'normalized_key',
                                'message' => trans_message('mdm.validation.import_update_only'),
                            ],
                        ],
                    ];

                    continue;
                }

                $accepted++;

                continue;
            }

            $issues[] = [
                'row' => $index + 1,
                'score' => $quality['score'],
                'issues' => $quality['issues'],
            ];
        }

        return MdmImportBatch::create([
            'organization_id' => $organizationId,
            'entity_type' => $entityType,
            'source' => $source,
            'status' => 'preview',
            'total_rows' => count($rows),
            'accepted_rows' => $accepted,
            'rejected_rows' => count($rows) - $accepted,
            'issues' => $issues,
            'created_by_user_id' => $userId,
        ]);
    }

    public function apply(int $organizationId, string $entityType, array $rows, ?int $userId = null, string $source = 'manual'): MdmImportBatch
    {
        return DB::transaction(function () use ($organizationId, $entityType, $rows, $userId, $source): MdmImportBatch {
            $batch = $this->preview($organizationId, $entityType, $rows, $userId, $source);
            $accepted = 0;
            $rejected = 0;
            $issues = $batch->issues ?? [];
            $modelClass = $this->registry->get($entityType)['model'];

            foreach ($rows as $index => $row) {
                $data = is_array($row) ? $this->registry->sanitizeValues($entityType, $row) : [];
                $quality = $this->qualityService->evaluate($entityType, $data, $organizationId);

                if ($quality['score'] < 70) {
                    $rejected++;

                    continue;
                }

                $this->assertScopedReferences($organizationId, $entityType, $data, $index);
                $data['organization_id'] = $organizationId;
                $record = $this->findRecordByQuality($organizationId, $entityType, $quality);

                $model = $record
                    ? $this->registry->query($entityType, $organizationId)->find($record->entity_id)
                    : null;

                if ($model === null) {
                    if (! $this->registry->supportsCreate($entityType)) {
                        $rejected++;
                        $issues[] = [
                            'row' => $index + 1,
                            'score' => $quality['score'],
                            'issues' => [
                                [
                                    'code' => 'existing_record_required',
                                    'field' => 'normalized_key',
                                    'message' => trans_message('mdm.validation.import_update_only'),
                                ],
                            ],
                        ];

                        continue;
                    }

                    $model = $modelClass::query()->create($data);
                } else {
                    $model->fill($data);
                    $model->save();
                }

                $this->recordService->syncModel($model, $entityType, $userId);
                $accepted++;
            }

            $batch->update([
                'status' => 'applied',
                'accepted_rows' => $accepted,
                'rejected_rows' => $rejected,
                'issues' => $issues,
            ]);

            return $batch->refresh();
        });
    }

    private function assertScopedReferences(int $organizationId, string $entityType, array $values, int|string $rowIndex): void
    {
        $errors = [];
        foreach ($this->referenceErrors($organizationId, $entityType, $values, true) as $field => $messages) {
            $errors["rows.{$rowIndex}.{$field}"] = $messages;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function referenceErrors(int $organizationId, string $entityType, array $values, bool $lock = false): array
    {
        $errors = [];
        foreach ($this->registry->referenceFields($entityType) as $field => $referenceEntityType) {
            if (! array_key_exists($field, $values) || $values[$field] === null || $values[$field] === '') {
                continue;
            }

            $referenceId = (int) $values[$field];
            $query = $referenceEntityType === 'measurement_unit'
                ? MeasurementUnit::query()->where(fn (Builder $query) => $query
                    ->where('organization_id', $organizationId)->orWhere('is_system', true))
                : $this->registry->query((string) $referenceEntityType, $organizationId);

            $query->whereKey($referenceId);
            if ($lock) {
                $query->sharedLock();
            }

            if ($query->first(['id']) === null) {
                $errors[$field] = [trans_message('mdm.validation.reference_not_found', [
                    'field' => $this->registry->fieldLabel($entityType, (string) $field),
                ])];
            }
        }

        return $errors;
    }

    private function findRecordByQuality(int $organizationId, string $entityType, array $quality): ?MdmRecord
    {
        $normalizedKey = $quality['normalized_values']['normalized_key'] ?? null;

        if ($normalizedKey === null) {
            return null;
        }

        return MdmRecord::query()
            ->where('organization_id', $organizationId)
            ->where('entity_type', $entityType)
            ->where('normalized_key', $normalizedKey)
            ->first();
    }
}
