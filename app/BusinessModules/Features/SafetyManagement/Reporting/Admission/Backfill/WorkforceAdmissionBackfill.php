<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services\SafetySiteAssignmentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class WorkforceAdmissionBackfill
{
    public function __construct(private SafetySiteAssignmentService $assignmentService) {}

    public function sourceCode(): string
    {
        return 'safety_site_workforce_assignments';
    }

    public function sourceSchemaVersion(): string
    {
        return 'workforce_admission_v1';
    }

    public function nextBatch(int $organizationId, int $afterId, int $limit = 500): Collection
    {
        return DB::table('workforce_employee_assignments')
            ->where('organization_id', $organizationId)
            ->where('id', '>', $afterId)
            ->where('status', 'active')
            ->whereNotNull('project_id')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->limit(min(max($limit, 1), 500))
            ->get();
    }

    public function apply(Collection $batch): array
    {
        $inputHashes = [];
        $outputHashes = [];
        $gaps = 0;
        foreach ($batch as $assignment) {
            if (! is_object($assignment)) {
                $gaps++;

                continue;
            }
            $siteIds = DB::table('safety_sites')
                ->where('organization_id', $assignment->organization_id)
                ->where('project_id', $assignment->project_id)
                ->whereDate('active_from', '<=', (string) $assignment->valid_from)
                ->where(static function ($query) use ($assignment): void {
                    $query->whereNull('active_until')
                        ->orWhereDate('active_until', '>=', (string) $assignment->valid_from);
                })
                ->orderBy('id')
                ->pluck('id');
            if ($siteIds->count() !== 1) {
                $gaps++;

                continue;
            }
            $payload = [
                'employee_id' => (int) $assignment->employee_id,
                'mapping_source' => 'workforce_employee_assignments',
                'organization_id' => (int) $assignment->organization_id,
                'project_id' => (int) $assignment->project_id,
                'safety_site_id' => (int) $siteIds->first(),
                'valid_from' => (string) $assignment->valid_from,
                'valid_to' => $assignment->valid_to === null ? null : (string) $assignment->valid_to,
                'workforce_assignment_id' => (int) $assignment->id,
            ];
            $expected = hash('sha256', CanonicalJson::encode($payload));
            try {
                $mapping = $this->assignmentService->assign(
                    $payload['organization_id'],
                    $payload['project_id'],
                    $payload['safety_site_id'],
                    $payload['workforce_assignment_id'],
                    $payload['employee_id'],
                    $payload['valid_from'],
                    $payload['valid_to'],
                    $payload['mapping_source'],
                );
            } catch (\Throwable) {
                $gaps++;

                continue;
            }
            $inputHashes[] = $expected;
            $outputHashes[] = hash('sha256', CanonicalJson::encode([
                'mapping_id' => (int) $mapping->id,
                'source_hash' => (string) $mapping->source_hash,
            ]));
        }

        return [
            'source_count' => $batch->count(),
            'projected_count' => count($outputHashes),
            'gap_count' => $gaps,
            'unknown_count' => 0,
            'input_hash' => hash('sha256', implode('', $inputHashes)),
            'output_hash' => hash('sha256', implode('', $outputHashes)),
            'source_watermark' => $batch->max('updated_at'),
        ];
    }

    public function synchronize(int $organizationId, int $limit = 500): array
    {
        $afterId = 0;
        $totals = ['source_count' => 0, 'projected_count' => 0, 'gap_count' => 0];
        do {
            $batch = $this->nextBatch($organizationId, $afterId, $limit);
            if ($batch->isEmpty()) {
                break;
            }
            $result = $this->apply($batch);
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $result[$key];
            }
            $afterId = (int) $batch->max('id');
        } while ($batch->count() === min(max($limit, 1), 500));

        return $totals;
    }
}
