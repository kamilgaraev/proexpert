<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\EstimateDocumentRowExtractor;

final class ProjectDocumentNormativeReferenceExtractor
{
    public function __construct(
        private readonly EstimateDocumentRowExtractor $rowExtractor = new EstimateDocumentRowExtractor(),
    ) {}

    /**
     * @param array<string, mixed> $analysis
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @return array<int, array<string, mixed>>
     */
    public function extract(array $analysis, array $localEstimate, array $section): array
    {
        $references = [];
        $seen = [];

        foreach ($analysis['source_documents'] ?? [] as $document) {
            if (!is_array($document)) {
                continue;
            }

            if (!DocumentEvidencePolicy::canScanNormativeReferences($document)) {
                continue;
            }

            foreach ($this->lines((string) ($document['text'] ?? '')) as $line) {
                $reference = $this->referenceFromLine($line, $document, $localEstimate, $section);

                if ($reference === null) {
                    continue;
                }

                $key = implode('|', [
                    $reference['normative_rate_code'] ?? '',
                    $reference['name'] ?? '',
                    $reference['quantity'] ?? '',
                    $reference['unit'] ?? '',
                ]);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line), preg_split('/\r\n|\r|\n/u', $text) ?: []),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $localEstimate
     * @param array<string, mixed> $section
     * @return array<string, mixed>|null
     */
    private function referenceFromLine(string $line, array $document, array $localEstimate, array $section): ?array
    {
        $row = $this->rowExtractor->extractFromLine($line);

        if ($row === null) {
            return null;
        }

        $code = (string) $row['code'];
        $codeKind = (string) $row['code_kind'];
        $scope = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? '');
        $category = $this->categoryForLine($line, $scope);

        if (!$this->isCompatibleCategory($category, $scope, (string) ($localEstimate['key'] ?? ''))) {
            return null;
        }

        $name = (string) $row['name'];
        $confidence = (string) $row['quantity_source'] === 'project_document' ? 0.84 : 0.7;
        $flags = ['normative_required'];

        if ((string) $row['quantity_source'] !== 'project_document') {
            $flags[] = 'quantity_review_required';
        }

        $metadata = [
            'generation_source' => 'project_document_normative_reference',
            'document_role' => 'project_documentation',
            'normative_reference_kind' => $codeKind,
            'original_normative_code' => $row['raw_code'],
            'normative_code_prefix' => $row['code_prefix'],
        ];
        $normativeRateCode = $code;

        if ($codeKind !== 'work_norm') {
            $normativeRateCode = null;
            $flags[] = 'normative_code_required';
            $metadata['normative_resource_code'] = $code;
            $metadata['requires_work_norm_selection'] = true;
        }

        return [
            'name' => $name,
            'normative_search_text' => $name,
            'normative_rate_code' => $normativeRateCode,
            'work_category' => $category,
            'unit' => (string) $row['unit'],
            'quantity' => (float) $row['quantity'],
            'quantity_formula' => 'project_document_norm:' . $code,
            'quantity_basis' => (string) $row['quantity_basis'],
            'source_refs' => [[
                'type' => 'project_document_norm_reference',
                'document_id' => $document['id'] ?? null,
                'filename' => $document['filename'] ?? null,
                'excerpt' => mb_substr($line, 0, 240),
            ]],
            'confidence' => $confidence,
            'validation_flags' => $flags,
            'metadata' => $metadata,
        ];
    }

    private function categoryForLine(string $line, string $scope): string
    {
        $text = mb_strtolower($line);

        return match (true) {
            str_contains($text, 'кабел') || str_contains($text, 'электр') || str_contains($text, 'щит') => 'electrical',
            str_contains($text, 'вент') || str_contains($text, 'воздуховод') => 'ventilation',
            str_contains($text, 'отоп') || str_contains($text, 'радиатор') => 'heating',
            str_contains($text, 'труб') || str_contains($text, 'водоснаб') || str_contains($text, 'канализац') => 'plumbing',
            str_contains($text, 'кров') || str_contains($text, 'пароизоляц') || str_contains($text, 'утепл') => 'roof',
            str_contains($text, 'бетон') || str_contains($text, 'арматур') || str_contains($text, 'опалуб') => 'foundation',
            str_contains($text, 'стен') || str_contains($text, 'клад') || str_contains($text, 'перегород') => 'walls',
            str_contains($text, 'пол') || str_contains($text, 'стяж') || str_contains($text, 'плит') => 'finishing',
            default => $scope !== '' ? $scope : 'custom',
        };
    }

    private function isCompatibleCategory(string $category, string $scope, string $packageKey): bool
    {
        if ($scope === '' || $scope === 'custom') {
            return true;
        }

        if ($category === $scope || str_contains($packageKey, $category)) {
            return true;
        }

        return match ($scope) {
            'site' => in_array($category, ['site', 'foundation'], true),
            'engineering' => in_array($category, ['electrical', 'plumbing', 'heating', 'ventilation'], true),
            'slabs' => in_array($category, ['slabs', 'foundation', 'industrial_floor', 'finishing'], true),
            'structural' => in_array($category, ['structural', 'metal_frame', 'foundation'], true),
            'facade' => in_array($category, ['facade', 'walls', 'roof'], true),
            'finishing' => in_array($category, ['finishing', 'walls'], true),
            default => false,
        };
    }
}
