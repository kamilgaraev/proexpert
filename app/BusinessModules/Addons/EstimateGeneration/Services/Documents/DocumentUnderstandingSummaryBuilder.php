<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Documents;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final class DocumentUnderstandingSummaryBuilder
{
    public function __construct(
        private readonly ConstructionDocumentClassifierService $classifier,
    ) {}

    /**
     * @param array<string, mixed> $drawingSummary
     * @param array<string, mixed> $factsSummary
     * @return array<string, mixed>
     */
    public function build(
        EstimateGenerationDocument $document,
        OcrRecognitionResult $recognition,
        array $drawingSummary,
        array $factsSummary
    ): array {
        $pageCount = count($recognition->pages);
        $classification = $this->classifier->classify(
            $document->filename,
            $document->mime_type,
            $pageCount,
            $recognition->text()
        );
        $documentProfile = is_array($drawingSummary['document_profile'] ?? null)
            ? $drawingSummary['document_profile']
            : [];
        $classifiedType = (string) ($classification['type'] ?? 'unknown');
        $documentRole = (string) ($documentProfile['document_role'] ?? '');
        $documentType = $this->documentType($classifiedType, $documentRole);
        $sourceFormat = (string) ($drawingSummary['source_format'] ?? $documentProfile['source_format'] ?? $this->sourceFormat($document->filename));
        $pageProfiles = is_array($drawingSummary['page_profiles'] ?? null)
            ? array_values($drawingSummary['page_profiles'])
            : [];
        $capabilities = $this->capabilities(
            $documentType,
            $classifiedType,
            $recognition,
            $drawingSummary,
            $factsSummary,
            $documentProfile,
        );

        return [
            'document_type' => $documentType,
            'type' => $documentType,
            'classified_type' => $classifiedType,
            'source_format' => $sourceFormat,
            'confidence' => $this->confidence($classification, $documentProfile, $documentType, $capabilities),
            'role_for_estimation' => $this->roleForEstimation(
                $documentType,
                $classifiedType,
                $capabilities,
                array_values(array_map('strval', $classification['reasons'] ?? [])),
            ),
            'reasons' => array_values(array_unique(array_map('strval', $classification['reasons'] ?? []))),
            'signals' => $this->signals($pageProfiles, $capabilities),
            'page_profiles' => $pageProfiles,
            'extracted_capabilities' => $capabilities,
            'pages_count' => $pageCount,
        ];
    }

    /**
     * @param array<string, mixed> $understanding
     * @return array<int, array<string, mixed>>
     */
    public function pageUnderstandingByNumber(array $understanding): array
    {
        $profiles = is_array($understanding['page_profiles'] ?? null) ? $understanding['page_profiles'] : [];
        $result = [];

        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            $pageNumber = (int) ($profile['page_number'] ?? 0);

            if ($pageNumber <= 0) {
                continue;
            }

            $result[$pageNumber] = [
                'page_role' => (string) ($profile['page_role'] ?? 'technical_document'),
                'confidence' => (float) ($profile['confidence'] ?? 0.5),
                'signals' => array_values(array_map('strval', is_array($profile['signals'] ?? null) ? $profile['signals'] : [])),
                'role_for_estimation' => $this->pageRoleForEstimation((string) ($profile['page_role'] ?? 'technical_document')),
            ];
        }

        return $result;
    }

    private function documentType(string $classifiedType, string $documentRole): string
    {
        if ($documentRole === 'floor_plan') {
            return 'floor_plan';
        }

        if (in_array($documentRole, ['work_volume_statement', 'specification', 'reference_estimate', 'technical_document'], true)) {
            return $documentRole;
        }

        return $classifiedType !== '' ? $classifiedType : 'unknown';
    }

    /**
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $documentProfile
     * @param array<string, mixed> $capabilities
     */
    private function confidence(array $classification, array $documentProfile, string $documentType, array $capabilities): float
    {
        $values = [];

        if (isset($classification['confidence']) && is_numeric($classification['confidence'])) {
            $values[] = (float) $classification['confidence'];
        }

        if (isset($documentProfile['confidence']) && is_numeric($documentProfile['confidence'])) {
            $values[] = (float) $documentProfile['confidence'];
        }

        if ($values === []) {
            return $documentType === 'unknown' ? 0.2 : 0.5;
        }

        $confidence = array_sum($values) / count($values);

        if (($capabilities['has_quantity_takeoffs'] ?? false) === true) {
            $confidence += 0.04;
        }

        if (($capabilities['requires_manual_review'] ?? false) === true) {
            $confidence -= 0.08;
        }

        return round(max(min($confidence, 0.99), 0.1), 4);
    }

    /**
     * @param array<string, mixed> $drawingSummary
     * @param array<string, mixed> $factsSummary
     * @param array<string, mixed> $documentProfile
     * @return array<string, bool>
     */
    private function capabilities(
        string $documentType,
        string $classifiedType,
        OcrRecognitionResult $recognition,
        array $drawingSummary,
        array $factsSummary,
        array $documentProfile
    ): array {
        $text = mb_strtolower($recognition->text());
        $takeoffsCount = (int) ($drawingSummary['takeoffs_count'] ?? 0);
        $roomCount = (int) ($drawingSummary['room_count'] ?? 0);
        $dimensionCount = (int) ($drawingSummary['dimension_count'] ?? 0);
        $hasSpecificationMarkers = $classifiedType === 'specification'
            || preg_match('/спецификац|ведомость|количество|поз\./u', $text) === 1;
        $hasWorkVolumeStatementMarkers = $classifiedType === 'work_volume_statement'
            || $documentType === 'work_volume_statement'
            || preg_match('/ведомость\s+(?:объемов|объёмов|работ)|объемы?\s+работ|объёмы?\s+работ/u', $text) === 1;
        $hasStrongEstimateMarkers = preg_match('/локальная смета|гранд-смет|итого по смете/u', $text) === 1;
        $hasEstimateMarkers = $classifiedType === 'estimate'
            || preg_match('/локальная смета|гранд-смет|гэсн|фер|фсбц|обоснование/u', $text) === 1;
        $requiresManualReview = (bool) ($documentProfile['requires_manual_review'] ?? false)
            || $documentType === 'unknown'
            || ($documentType === 'floor_plan' && $takeoffsCount === 0)
            || ($classifiedType === 'drawing_cad' && $takeoffsCount === 0)
            || (($factsSummary['conflicts'] ?? []) !== []);

        return [
            'has_room_areas' => $roomCount > 0,
            'has_dimensions' => $dimensionCount > 0,
            'has_quantity_takeoffs' => $takeoffsCount > 0,
            'has_work_volume_statement_markers' => $hasWorkVolumeStatementMarkers,
            'has_specification_markers' => $hasSpecificationMarkers,
            'has_estimate_markers' => $hasEstimateMarkers,
            'has_strong_estimate_markers' => $hasStrongEstimateMarkers,
            'requires_cad_geometry_pipeline' => $classifiedType === 'drawing_cad',
            'requires_manual_review' => $requiresManualReview,
        ];
    }

    /**
     * @param array<string, bool> $capabilities
     * @param array<int, string> $classificationReasons
     */
    private function roleForEstimation(
        string $documentType,
        string $classifiedType,
        array $capabilities,
        array $classificationReasons
    ): string
    {
        if (($capabilities['requires_manual_review'] ?? false) === true && $documentType === 'unknown') {
            return 'needs_review';
        }

        $isQuantitySourceDocument = in_array($documentType, ['work_volume_statement', 'specification'], true)
            || in_array($classifiedType, ['work_volume_statement', 'specification'], true);
        $hasStrongEstimateMarkers = ($capabilities['has_strong_estimate_markers'] ?? false) === true
            || in_array('strong_estimate_marker', $classificationReasons, true);

        if (
            $documentType === 'reference_estimate'
            || ($classifiedType === 'estimate' && (! $isQuantitySourceDocument || $hasStrongEstimateMarkers))
        ) {
            return 'reference_estimate';
        }

        if (
            $isQuantitySourceDocument
            && (($capabilities['has_work_volume_statement_markers'] ?? false) === true
                || ($capabilities['has_specification_markers'] ?? false) === true)
            && ($capabilities['has_quantity_takeoffs'] ?? false) === true
        ) {
            return 'quantity_source';
        }

        if (
            in_array($documentType, ['work_volume_statement', 'specification'], true)
            || in_array($classifiedType, ['work_volume_statement', 'specification'], true)
        ) {
            return 'quantity_source';
        }

        if ($documentType === 'floor_plan' || str_starts_with($classifiedType, 'drawing_')) {
            return 'geometry_source';
        }

        if (($capabilities['requires_manual_review'] ?? false) === true) {
            return 'needs_review';
        }

        return 'context_document';
    }

    private function pageRoleForEstimation(string $pageRole): string
    {
        return match ($pageRole) {
            'floor_plan' => 'geometry_source',
            'work_volume_statement' => 'quantity_source',
            'specification' => 'quantity_source',
            'reference_estimate' => 'reference_estimate',
            default => 'context_document',
        };
    }

    /**
     * @param array<int, mixed> $pageProfiles
     * @param array<string, bool> $capabilities
     * @return array<int, string>
     */
    private function signals(array $pageProfiles, array $capabilities): array
    {
        $signals = [];

        foreach ($pageProfiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            foreach (($profile['signals'] ?? []) as $signal) {
                $signals[] = (string) $signal;
            }
        }

        foreach ($capabilities as $capability => $enabled) {
            if ($enabled) {
                $signals[] = $capability;
            }
        }

        return array_values(array_unique($signals));
    }

    private function sourceFormat(string $filename): string
    {
        $extension = mb_strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'pdf' => 'pdf',
            'dwg', 'dxf' => 'cad',
            'png', 'jpg', 'jpeg', 'webp', 'tif', 'tiff', 'bmp' => 'image',
            'xls', 'xlsx', 'csv' => 'spreadsheet',
            'doc', 'docx', 'rtf' => 'text_document',
            default => 'unknown',
        };
    }
}
