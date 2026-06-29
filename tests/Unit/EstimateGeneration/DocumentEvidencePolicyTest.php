<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;
use PHPUnit\Framework\TestCase;

final class DocumentEvidencePolicyTest extends TestCase
{
    public function test_document_without_understanding_role_is_not_primary_evidence(): void
    {
        $document = [
            'status' => 'ready',
            'quality' => [
                'level' => 'good',
            ],
            'facts_summary' => [
                'total_area_m2' => 125.0,
            ],
        ];

        self::assertFalse(DocumentEvidencePolicy::canUseQuantityEvidence($document));
        self::assertFalse(DocumentEvidencePolicy::canUseScopeEvidence($document));
        self::assertFalse(DocumentEvidencePolicy::canScanNormativeReferences($document));
    }

    public function test_quantity_and_geometry_sources_can_supply_quantity_evidence(): void
    {
        self::assertTrue(DocumentEvidencePolicy::canUseQuantityEvidence($this->documentWithRole('quantity_source')));
        self::assertTrue(DocumentEvidencePolicy::canUseQuantityEvidence($this->documentWithRole('geometry_source')));
        self::assertTrue(DocumentEvidencePolicy::canUseScopeEvidence($this->documentWithRole('context_document')));
    }

    public function test_reference_estimate_and_needs_review_are_not_primary_evidence(): void
    {
        foreach (['reference_estimate', 'needs_review'] as $role) {
            self::assertFalse(DocumentEvidencePolicy::canUseQuantityEvidence($this->documentWithRole($role)));
            self::assertFalse(DocumentEvidencePolicy::canUseScopeEvidence($this->documentWithRole($role)));
            self::assertFalse(DocumentEvidencePolicy::canScanNormativeReferences($this->documentWithRole($role)));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function documentWithRole(string $role): array
    {
        return [
            'status' => 'ready',
            'quality' => [
                'level' => 'good',
            ],
            'facts_summary' => [
                'document_understanding' => [
                    'role_for_estimation' => $role,
                ],
            ],
        ];
    }
}
