<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Services;

use App\BusinessModules\Features\SafetyManagement\DTOs\SafetyComplianceRequirementResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SafetyEvidenceVersionResolver
{
    public function effective(
        int $organizationId,
        string $evidenceType,
        int $evidenceId,
        CarbonImmutable $asOf,
    ): ?array {
        $version = DB::table('safety_evidence_versions')
            ->where('organization_id', $organizationId)
            ->where('evidence_type', $evidenceType)
            ->where('evidence_id', $evidenceId)
            ->where('effective_at', '<=', $asOf)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first(['id', 'content', 'content_hash']);
        if ($version === null) {
            return null;
        }
        $content = json_decode((string) $version->content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($content) || ($content['_deleted'] ?? false) === true) {
            return null;
        }

        return [
            'id' => (int) $version->id,
            'content' => $content,
            'hash' => (string) $version->content_hash,
        ];
    }

    public function requirement(
        int $organizationId,
        int $employeeId,
        array $requirement,
        CarbonImmutable $date,
        CarbonImmutable $asOf,
    ): SafetyComplianceRequirementResult {
        $type = (string) $requirement['type'];
        $code = (string) $requirement['code'];
        $versions = DB::table('safety_evidence_versions')
            ->where('organization_id', $organizationId)
            ->where('employee_id', $employeeId)
            ->whereIn('evidence_type', ['employee_requirement', $type])
            ->where('effective_at', '<=', $asOf)
            ->orderByRaw("CASE WHEN evidence_type = 'employee_requirement' THEN 0 ELSE 1 END")
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get(['id', 'evidence_type', 'evidence_id', 'content', 'content_hash'])
            ->unique(static fn (object $row): string => $row->evidence_type.':'.$row->evidence_id);
        foreach ($versions as $version) {
            $content = json_decode((string) $version->content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($content) || ($content['_deleted'] ?? false) === true) {
                continue;
            }
            $source = $version->evidence_type === 'briefing'
                ? ($content['briefing'] ?? [])
                : $content;
            if (! is_array($source) || ! $this->matches($version->evidence_type, $source, $requirement)) {
                continue;
            }
            $validUntil = isset($source['valid_until'])
                ? CarbonImmutable::parse((string) $source['valid_until'])
                : null;
            $expired = $validUntil !== null && $validUntil->startOfDay()->lt($date->startOfDay());
            if ($version->evidence_type === 'employee_requirement' && $expired) {
                continue;
            }
            [$status, $severity] = $this->status($version->evidence_type, $content, $expired);

            return new SafetyComplianceRequirementResult(
                code: $code,
                type: $type,
                label: (string) ($requirement['label'] ?? $code),
                status: $status,
                severity: $severity,
                sourceType: (string) $version->evidence_type,
                sourceId: (int) $version->evidence_id,
                validUntil: $validUntil,
            );
        }

        return new SafetyComplianceRequirementResult(
            code: $code,
            type: $type,
            label: (string) ($requirement['label'] ?? $code),
            status: 'missing',
            severity: ($requirement['required'] ?? true) ? 'critical' : 'warning',
        );
    }

    private function matches(string $evidenceType, array $source, array $requirement): bool
    {
        $code = (string) $requirement['code'];

        return match ($evidenceType) {
            'employee_requirement' => ($source['requirement_type'] ?? null) === $requirement['type']
                && ($source['requirement_code'] ?? null) === $code
                && in_array(
                    $source['status'] ?? null,
                    ['fulfilled', 'valid', 'approved', 'completed', 'waived'],
                    true,
                ),
            'training' => ($source['program_code'] ?? null) === $code,
            'medical_exam' => ($source['exam_type'] ?? null) === $code,
            'ppe' => ($source['ppe_code'] ?? null) === $code,
            'briefing' => ($source['briefing_type'] ?? null) === $code,
            default => false,
        };
    }

    private function status(string $type, array $content, bool $expired): array
    {
        if ($expired) {
            return ['expired', 'critical'];
        }
        $source = $type === 'briefing' ? ($content['briefing'] ?? []) : $content;
        $participant = $content['participant'] ?? [];

        return match ($type) {
            'employee_requirement' => ($source['status'] ?? null) === 'waived'
                ? ['waived', 'warning'] : ['fulfilled', 'ok'],
            'training' => ($source['result'] ?? null) === 'passed'
                ? ['fulfilled', 'ok'] : ['failed', 'critical'],
            'medical_exam' => match ($source['result'] ?? null) {
                'fit' => ['fulfilled', 'ok'],
                'fit_with_restrictions' => ['restricted', 'warning'],
                default => ['not_fit', 'critical'],
            },
            'ppe' => ($source['status'] ?? null) === 'issued'
                ? ['fulfilled', 'ok'] : ['missing', 'critical'],
            'briefing' => ($source['status'] ?? null) === 'completed'
                && ($participant['signature_status'] ?? null) === 'signed'
                && ($participant['signed_at'] ?? null) !== null
                ? ['fulfilled', 'ok'] : ['missing', 'critical'],
            default => ['missing', 'critical'],
        };
    }
}
