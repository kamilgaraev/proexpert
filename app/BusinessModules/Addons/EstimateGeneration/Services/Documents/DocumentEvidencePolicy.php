<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

final class DocumentEvidencePolicy
{
    private const TRUSTED_STATUSES = ['ready', 'uploaded'];
    private const TRUSTED_QUALITY_LEVELS = ['good', 'acceptable'];
    private const NON_PRIMARY_ROLES = ['reference_estimate', 'needs_review'];
    private const QUANTITY_EVIDENCE_ROLES = ['quantity_source', 'geometry_source'];

    /**
     * @param array<string, mixed> $document
     */
    public static function isTrusted(array $document): bool
    {
        if (($document['status'] ?? null) === 'ignored') {
            return false;
        }

        $quality = is_array($document['quality'] ?? null) ? $document['quality'] : [];
        $level = (string) ($quality['level'] ?? '');

        if ($level !== '' && !in_array($level, self::TRUSTED_QUALITY_LEVELS, true)) {
            return false;
        }

        return in_array((string) ($document['status'] ?? 'ready'), self::TRUSTED_STATUSES, true);
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function roleForEstimation(array $document): string
    {
        $factsSummary = is_array($document['facts_summary'] ?? null) ? $document['facts_summary'] : [];
        $understanding = is_array($document['document_understanding'] ?? null)
            ? $document['document_understanding']
            : (is_array($factsSummary['document_understanding'] ?? null) ? $factsSummary['document_understanding'] : []);

        return (string) ($understanding['role_for_estimation'] ?? '');
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function canUseQuantityEvidence(array $document): bool
    {
        $role = self::roleForEstimation($document);

        return in_array($role, self::QUANTITY_EVIDENCE_ROLES, true);
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function canUseScopeEvidence(array $document): bool
    {
        $role = self::roleForEstimation($document);

        return $role !== '' && !in_array($role, self::NON_PRIMARY_ROLES, true);
    }

    /**
     * @param array<string, mixed> $document
     */
    public static function canScanNormativeReferences(array $document): bool
    {
        return self::isTrusted($document) && self::canUseScopeEvidence($document);
    }
}
