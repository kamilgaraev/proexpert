<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use App\BusinessModules\Core\Mdm\Models\MdmImportBatch;
use App\BusinessModules\Core\Mdm\Models\MdmRecord;
use Illuminate\Support\Facades\DB;

class MdmImportService
{
    public function __construct(
        private readonly MdmQualityService $qualityService,
        private readonly MdmEntityRegistry $registry,
        private readonly MdmRecordService $recordService
    ) {
    }

    public function preview(int $organizationId, string $entityType, array $rows, ?int $userId = null, string $source = 'manual'): MdmImportBatch
    {
        $issues = [];
        $accepted = 0;

        foreach ($rows as $index => $row) {
            $quality = $this->qualityService->evaluate($entityType, is_array($row) ? $row : []);

            if ($quality['score'] >= 70) {
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
                $data = is_array($row) ? $row : [];
                $quality = $this->qualityService->evaluate($entityType, $data);

                if ($quality['score'] < 70) {
                    $rejected++;
                    continue;
                }

                $data['organization_id'] = $organizationId;
                $record = null;
                $normalizedKey = $quality['normalized_values']['normalized_key'] ?? null;

                if ($normalizedKey !== null) {
                    $record = MdmRecord::query()
                        ->where('organization_id', $organizationId)
                        ->where('entity_type', $entityType)
                        ->where('normalized_key', $normalizedKey)
                        ->first();
                }

                $model = $record
                    ? $this->registry->query($entityType, $organizationId)->find($record->entity_id)
                    : null;

                if ($model === null) {
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
}
