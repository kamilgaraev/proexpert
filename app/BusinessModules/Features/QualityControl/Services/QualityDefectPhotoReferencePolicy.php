<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Services;

use App\BusinessModules\Features\QualityControl\Models\QualityDefect;
use App\BusinessModules\Features\QualityControl\Models\QualityDefectPhoto;

final class QualityDefectPhotoReferencePolicy
{
    public function belongsToDefect(QualityDefectPhoto $photo, QualityDefect $defect): bool
    {
        return (int) $defect->id > 0
            && (int) $defect->organization_id > 0
            && (int) $photo->quality_defect_id === (int) $defect->id
            && (int) $photo->organization_id === (int) $defect->organization_id;
    }

    public function externalUrl(string $reference): ?string
    {
        if (preg_match('/[\x00-\x20\x7F]/', $reference) === 1 || str_contains($reference, '\\')) {
            return null;
        }
        $parts = parse_url($reference);
        if (! is_array($parts) || empty($parts['host'])
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        return $reference;
    }

    public function storagePath(string $reference, QualityDefect $defect): ?string
    {
        if ((int) $defect->id <= 0 || (int) $defect->organization_id <= 0
            || str_contains($reference, '\\')
            || preg_match('/[\x00-\x20\x7F?#]/', $reference) === 1) {
            return null;
        }

        $path = strncasecmp($reference, 's3://', 5) === 0 ? substr($reference, 5) : ltrim($reference, '/');
        $qualityPrefix = preg_quote("org-{$defect->organization_id}/quality-control/defects/{$defect->id}/", '~');
        $uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
        if (preg_match('~\A'.$qualityPrefix.$uuid.'\.[a-z0-9_-]*\z~i', $path) === 1) {
            return $path;
        }

        $designPrefix = preg_quote("org-{$defect->organization_id}/design-management/issues/{$defect->id}/photo/", '~');
        if ($defect->kind === 'project'
            && preg_match('~\A'.$designPrefix.'[0-9a-f]{64}\.(?:jpg|png|webp)\z~', $path) === 1) {
            return $path;
        }

        return null;
    }
}
