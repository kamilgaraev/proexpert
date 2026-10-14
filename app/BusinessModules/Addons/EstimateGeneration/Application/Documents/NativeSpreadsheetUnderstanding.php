<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary;

final readonly class NativeSpreadsheetUnderstanding
{
    public const CONTRACT = 'native-spreadsheet-understanding:v1';

    public function summarize(?DocumentUnitPublication $publication, string $structureStatus): array
    {
        $types = [];
        $review = $structureStatus !== 'available' || ($publication?->quarantinedItems ?? []) !== [];
        $claims = array_column($publication?->claims ?? [], null, 'id');
        $accepted = 0;
        $roomArea = false;
        foreach ($publication?->decisions ?? [] as $decision) {
            $claim = $claims[$decision->claimId] ?? null;
            if ($decision->status !== 'accepted') {
                $review = true;

                continue;
            }
            if ($claim !== null && ($claim->value['type'] ?? null) === 'number'
                && $publication?->numericSources?->certifies($claim) === true) {
                $entityType = FactVocabulary::entityType($claim->entityKey);
                if ($claim->factType !== 'quantity' && ($entityType === null || ! FactVocabulary::supports($entityType, $claim->factType))) {
                    $review = true;

                    continue;
                }
                $group = in_array($claim->factType, ['area', 'plan_area'], true) ? 'area'
                    : (in_array($claim->factType, ['equipment_count'], true) ? 'count'
                        : ($claim->factType === 'quantity' ? null : 'length'));
                if ($group !== null && FactVocabulary::factor($group, $claim->unit) === null) {
                    $review = true;

                    continue;
                }
                $types[$claim->factType] = true;
                $roomArea = $roomArea || ($claim->factType === 'area' && $entityType === 'room');
                $accepted++;
            }
        }

        return [
            'contract' => self::CONTRACT,
            'document_type' => 'spreadsheet',
            'role_for_estimation' => $review ? 'needs_review' : ($accepted > 0 ? 'quantity_source' : 'context_document'),
            'extracted_capabilities' => [
                'has_room_areas' => $roomArea,
                'has_dimensions' => array_intersect(array_keys($types), ['length', 'width', 'height', 'wall_length', 'wall_height', 'opening_width', 'opening_height']) !== [],
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
