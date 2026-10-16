<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class NativeCadUnderstanding
{
    public const CONTRACT = 'native-cad-understanding:v1';

    public function summarize(?DocumentUnitPublication $publication, array $structure, array $warnings): array
    {
        $review = ($structure['status'] ?? null) !== 'available'
            || ($structure['unit_status'] ?? null) !== 'confirmed'
            || $warnings !== [] || ($publication?->quarantinedItems ?? []) !== [];
        $claims = array_column($publication?->claims ?? [], null, 'id');
        $accepted = 0;
        foreach ($publication?->decisions ?? [] as $decision) {
            $claim = $claims[$decision->claimId] ?? null;
            if ($decision->status !== 'accepted' || $claim === null
                || $claim->factType !== 'dimension_chain'
                || $publication?->numericSources?->certifies($claim) !== true) {
                $review = true;

                continue;
            }
            $accepted++;
        }

        return [
            'contract' => self::CONTRACT,
            'document_type' => 'drawing',
            'role_for_estimation' => $review ? 'needs_review' : ($accepted > 0 ? 'quantity_source' : 'context_document'),
            'extracted_capabilities' => [
                'has_room_areas' => false,
                'has_dimensions' => $accepted > 0,
                'has_axes' => false,
                'has_title_block' => false,
                'has_pdf_geometry' => false,
                'has_quantity_takeoffs' => false,
                'has_work_volume_statement_markers' => false,
                'has_specification_markers' => false,
                'has_estimate_markers' => false,
                'has_strong_estimate_markers' => false,
                'requires_cad_geometry_pipeline' => false,
                'requires_manual_review' => $review,
                'has_quantities' => $accepted > 0,
                'accepted_quantity_claims' => $accepted,
            ],
            'verification_status' => 'native_source_checked',
        ];
    }
}
