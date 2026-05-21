<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Services;

use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocument;
use App\BusinessModules\Features\ExecutiveDocumentation\Models\ExecutiveDocumentSet;

final class ExecutiveDocumentNumberGenerator
{
    public function generateSetNumber(int $organizationId): string
    {
        $prefix = 'ED-' . now()->format('Ym') . '-';
        $count = ExecutiveDocumentSet::query()
            ->where('organization_id', $organizationId)
            ->where('set_number', 'like', $prefix . '%')
            ->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    public function generateDocumentNumber(int $organizationId, string $documentType): string
    {
        $prefix = $this->documentPrefix($documentType) . '-' . now()->format('Ym') . '-';
        $count = ExecutiveDocument::query()
            ->where('organization_id', $organizationId)
            ->where('document_type', $documentType)
            ->where('created_at', '>=', now()->copy()->startOfMonth())
            ->count();

        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    private function documentPrefix(string $documentType): string
    {
        return match ($documentType) {
            'hidden_work_act' => 'АСР',
            'responsible_structure_act' => 'АОК',
            'engineering_network_section_act' => 'АИС',
            default => 'ИД',
        };
    }
}
