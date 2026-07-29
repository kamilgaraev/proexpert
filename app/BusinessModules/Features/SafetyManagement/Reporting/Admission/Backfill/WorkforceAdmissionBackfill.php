<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Backfill;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models\SafetySiteWorkforceAssignment;
use Illuminate\Support\Collection;

final readonly class WorkforceAdmissionBackfill
{
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
        return SafetySiteWorkforceAssignment::query()
            ->where('organization_id', $organizationId)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(min(max($limit, 1), 500))
            ->get();
    }

    public function apply(Collection $batch): array
    {
        $hashes = [];
        $gaps = 0;
        foreach ($batch as $assignment) {
            if (! $assignment instanceof SafetySiteWorkforceAssignment) {
                $gaps++;

                continue;
            }
            $payload = [
                'employee_id' => (int) $assignment->employee_id,
                'mapping_source' => (string) $assignment->mapping_source,
                'organization_id' => (int) $assignment->organization_id,
                'project_id' => (int) $assignment->project_id,
                'safety_site_id' => (int) $assignment->safety_site_id,
                'valid_from' => $assignment->valid_from->toDateString(),
                'valid_to' => $assignment->valid_to?->toDateString(),
                'workforce_assignment_id' => (int) $assignment->workforce_assignment_id,
            ];
            $expected = hash('sha256', CanonicalJson::encode($payload));
            if (! hash_equals($expected, (string) $assignment->source_hash)) {
                $gaps++;

                continue;
            }
            $hashes[] = $expected;
        }

        return [
            'source_count' => $batch->count(),
            'projected_count' => count($hashes),
            'gap_count' => $gaps,
            'unknown_count' => 0,
            'input_hash' => hash('sha256', implode('', $hashes)),
            'output_hash' => hash('sha256', implode('', $hashes)),
        ];
    }
}
