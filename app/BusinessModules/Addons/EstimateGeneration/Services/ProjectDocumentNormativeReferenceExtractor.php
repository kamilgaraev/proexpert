<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;
use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\EstimateDocumentRowExtractor;
use InvalidArgumentException;

final class ProjectDocumentNormativeReferenceExtractor
{
    public const MAX_REFERENCES = 10_000;

    public function __construct(
        private readonly EstimateDocumentRowExtractor $rowExtractor = new EstimateDocumentRowExtractor,
    ) {}

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
     * @return array<int, array<string, mixed>>
     */
    public function extract(array $analysis, array $localEstimate, array $section, ?int $referenceLimit = null): array
    {
        $references = [];
        $seen = [];

        foreach ($analysis['source_documents'] ?? [] as $documentIndex => $document) {
            if (! is_array($document)) {
                continue;
            }

            if (! DocumentEvidencePolicy::canScanNormativeReferences($document)) {
                continue;
            }

            $text = (string) ($document['text'] ?? '');
            $documentIdentity = ['id' => $document['id'] ?? ['input_index' => $documentIndex],
                'version' => $document['source_version'] ?? null, 'text' => hash('sha256', $text)];
            foreach ($this->lines($text) as $lineIndex => $line) {
                $reference = $this->referenceFromLine($line, $document, $localEstimate, $section);

                if ($reference === null) {
                    continue;
                }

                // Deduplicate delivery of one source observation, never different physical work rows.
                $key = json_encode([$documentIdentity, $lineIndex], JSON_THROW_ON_ERROR);

                if (isset($seen[$key])) {
                    continue;
                }

                if (count($references) >= min($referenceLimit ?? self::MAX_REFERENCES, self::MAX_REFERENCES)) {
                    throw new InvalidArgumentException('estimate_work_items_exceed_approved_plan');
                }
                $seen[$key] = true;
                $reference['metadata']['source_observation_id'] = hash('sha256', $key);
                $reference['source_refs'][0]['line_number'] = $lineIndex + 1;
                if (is_string($document['source_version'] ?? null)) {
                    $reference['source_refs'][0]['source_version'] = $document['source_version'];
                }
                $references[] = $reference;
            }
        }

        return $references;
    }

    /**
     * @return \Generator<int, string>
     */
    private function lines(string $text): \Generator
    {
        $offset = 0;
        $index = 0;
        $length = strlen($text);
        while ($offset < $length) {
            $size = strcspn($text, "\r\n", $offset);
            $line = trim(substr($text, $offset, $size));
            if ($line !== '') {
                yield $index => $line;
            }
            $offset += $size;
            $offset += substr($text, $offset, 2) === "\r\n" ? 2 : 1;
            $index++;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $localEstimate
     * @param  array<string, mixed>  $section
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

        if (! $this->isCompatibleCategory($category, $scope, (string) ($localEstimate['key'] ?? ''))) {
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
            'quantity_formula' => 'project_document_norm:'.$code,
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
