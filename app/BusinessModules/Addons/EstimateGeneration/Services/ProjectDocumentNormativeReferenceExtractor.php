<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\DocumentEvidencePolicy;

final class ProjectDocumentNormativeReferenceExtractor
{
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
        $referenceCode = $this->referenceCodeFromLine($line);

        if ($referenceCode === null) {
            return null;
        }

        $code = $referenceCode['code'];
        $scope = (string) ($localEstimate['scope_type'] ?? $section['construction_part'] ?? '');
        $category = $this->categoryForLine($line, $scope);

        if (!$this->isCompatibleCategory($category, $scope, (string) ($localEstimate['key'] ?? ''))) {
            return null;
        }

        $quantity = $this->quantityFromLine($line);
        $name = $this->nameFromLine($line, $code);
        $confidence = $quantity['source'] === 'project_document' ? 0.84 : 0.7;
        $flags = ['normative_required'];

        if ($quantity['source'] !== 'project_document') {
            $flags[] = 'quantity_review_required';
        }

        $metadata = [
            'generation_source' => 'project_document_normative_reference',
            'document_role' => 'project_documentation',
            'normative_reference_kind' => $referenceCode['kind'],
            'original_normative_code' => $referenceCode['raw_code'],
        ];
        $normativeRateCode = $code;

        if ($referenceCode['kind'] !== 'work_norm') {
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
            'unit' => $quantity['unit'],
            'quantity' => $quantity['value'],
            'quantity_formula' => 'project_document_norm:' . $code,
            'quantity_basis' => $quantity['basis'],
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

    /**
     * @return array{kind: string, code: string, raw_code: string}|null
     */
    private function referenceCodeFromLine(string $line): ?array
    {
        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:ГЭСН|ФЕР|ТЕР)?\s*(?<code>\d{2}-\d{2}-\d{3}-\d{2,3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'work_norm',
                'code' => (string) $match['code'],
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:ФСБЦ|КСР)?\s*(?<code>\d{2}\.\d\.\d{2}\.\d{2}-\d{4}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'fsbc_resource',
                'code' => (string) $match['code'],
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:ФСБЦ|КСР)?\s*(?<code>\d{2}\.\d{2}\.\d{2}-\d{3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'fsbc_machine_resource',
                'code' => (string) $match['code'],
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        if (preg_match('/(?<![\p{L}\p{N}])(?<raw>(?:ФСБЦ|КСР)?\s*(?<code>\d{1,2}-\d{3}-\d{2,3}))(?![\p{L}\p{N}])/u', $line, $match) === 1) {
            return [
                'kind' => 'ksr_resource',
                'code' => (string) $match['code'],
                'raw_code' => trim((string) $match['raw']),
            ];
        }

        return null;
    }

    /**
     * @return array{value: float, unit: string, basis: string, source: string}
     */
    private function quantityFromLine(string $line): array
    {
        if (preg_match('/(\d+(?:[,.]\d+)?)\s*(100\s*)?(м2|м²|м3|м³|м|шт\.?|т|кг|компл\.?)/ui', $line, $match) === 1) {
            $multiplier = trim((string) ($match[2] ?? '')) === '100' ? 100.0 : 1.0;

            return [
                'value' => round((float) str_replace(',', '.', $match[1]) * $multiplier, 4),
                'unit' => $this->normalizeUnit((string) $match[3]),
                'basis' => 'Количество найдено в проектной документации рядом с явной ссылкой на норму.',
                'source' => 'project_document',
            ];
        }

        return [
            'value' => 1.0,
            'unit' => 'компл',
            'basis' => 'В проектной документации найдена ссылка на норму без надежного количества.',
            'source' => 'review',
        ];
    }

    private function normalizeUnit(string $unit): string
    {
        $unit = mb_strtolower(trim($unit, ". \t\n\r\0\x0B"));

        return match ($unit) {
            'м²' => 'м2',
            'м³' => 'м3',
            'шт' => 'шт',
            'компл' => 'компл',
            default => $unit,
        };
    }

    private function nameFromLine(string $line, string $code): string
    {
        $name = trim(str_replace($code, '', preg_replace('/^(?:ГЭСН|ФЕР|ТЕР|ФСБЦ|КСР)\s*/u', '', $line) ?? $line));
        $name = trim(preg_replace('/\s{2,}/u', ' ', $name) ?? $name);
        $name = trim(preg_replace('/^\W+|\W+$/u', '', $name) ?? $name);

        return $name !== '' ? mb_substr($name, 0, 180) : 'Работа по норме ' . $code;
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
