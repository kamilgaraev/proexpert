<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Services\Documents\QuantityStatementLineParser;
use App\BusinessModules\Addons\EstimateGeneration\Services\ProjectDocumentNormativeReferenceExtractor;

/** Routes observations once; classification never confirms physical identity or quantity. */
final readonly class DocumentNormativeReferenceAllocator
{
    public function __construct(
        private ProjectDocumentNormativeReferenceExtractor $extractor = new ProjectDocumentNormativeReferenceExtractor,
        private QuantityStatementLineParser $quantities = new QuantityStatementLineParser,
    ) {}

    /** @return array{by_package:array<string,list<array<string,mixed>>>,unresolved:list<array<string,mixed>>} */
    public function allocate(array $analysis, array $estimates, ?int $limit = null): array
    {
        $result = ['by_package' => [], 'unresolved' => []];
        // A single scan enforces the structural ceiling globally, including historical operations.
        $references = $this->extractor->extract($analysis, ['key' => 'document_source', 'scope_type' => 'custom'], [], $limit);
        foreach ($references as $reference) {
            $package = $this->package($reference, $estimates);
            if ($package === null) {
                $result['unresolved'][] = $reference;
            } else {
                $result['by_package'][$package][] = $reference;
            }
        }

        return $result;
    }

    private function package(array $reference, array $estimates): ?string
    {
        $name = (string) $reference['name'];
        $parsed = $this->quantities->parse($name.' '.$reference['quantity'].' '.$reference['unit']);
        $quantityKey = $parsed['quantity_key'] ?? null;
        $text = mb_strtolower($name);
        $keys = array_column($estimates, 'key');
        $preferred = match (true) {
            is_string($quantityKey) && str_starts_with($quantityKey, 'foundation.') => ['foundation', 'foundations', 'structural_foundation', 'custom-foundation'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'earth.') => ['earthworks', 'custom-earthworks'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'rough.') => ['rough_finishing', 'office_finishing', 'custom-finishing'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'finish.') => ['finish_finishing', 'office_finishing', 'custom-finishing'],
            $quantityKey === 'openings.gates' => ['openings', 'gates', 'custom-openings'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'openings.') => ['openings', 'entrance_group', 'custom-openings'],
            $quantityKey === 'warehouse.lighting' || str_contains($text, 'освещ') => ['lighting', 'custom-electrical'],
            $quantityKey === 'office.ceiling' || $quantityKey === 'sanitary.tile' => ['finish_finishing', 'office_finishing', 'custom-finishing'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'sewerage.') => ['sewerage', 'water_sewerage', 'custom-sewerage'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'plumbing.') => ['plumbing', 'water_sewerage', 'custom-plumbing'],
            $quantityKey === 'sanitary.points' => ['sanitary_rooms', 'plumbing', 'water_sewerage', 'custom-plumbing'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'heating.') => ['heating', 'custom-heating'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'ventilation.') => ['ventilation', 'custom-ventilation'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'electrical.') => ['electrical', 'power_supply', 'custom-electrical'],
            ($reference['work_category'] ?? null) === 'electrical' => ['electrical', 'power_supply', 'custom-electrical'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'warehouse.floor_') => ['industrial_floor'],
            is_string($quantityKey) && str_starts_with($quantityKey, 'roof.') => ['roof', 'roofing', 'custom-roof'],
            str_contains($text, 'перекрыт') => ['slabs'],
            str_contains($text, 'стенов') && str_contains($text, 'панел')
                && ! str_contains($text, 'внутрен') && ! str_contains($text, 'перегород')
                && (in_array('envelope', $keys, true) || str_contains($text, 'сэндвич')) => ['envelope', 'facade'],
            str_contains($text, 'фасад') => ['facade', 'envelope'],
            preg_match('/(?<!\p{L})стен(?:а|ы|у|е|ой|ою|ам|ами|ах|ов\p{L}*)?(?!\p{L})/u', $text) === 1 || preg_match('/(?<!\p{L})клад(?:к|оч)\p{L}*/u', $text) === 1 || str_contains($text, 'перегород') => ['walls', 'office_partitions', 'custom-walls'],
            ($reference['work_category'] ?? null) === 'walls' => ['walls', 'office_partitions', 'custom-walls'],
            default => [],
        };
        foreach ($preferred as $key) {
            if (in_array($key, $keys, true)) {
                return $key;
            }
        }
        // A known subpackage that is outside the chosen scope is not relabelled as another one.
        if ($preferred !== []) {
            return null;
        }
        $category = $reference['work_category'] ?? 'custom';
        if ($category === 'roof' && ! str_contains($text, 'кров') && ! str_contains($text, 'крыш')) {
            return null;
        }
        if (in_array($category, ['custom', 'unknown', ''], true)) {
            return null;
        }
        $matches = array_values(array_filter($estimates, static fn (array $estimate): bool => ($estimate['scope_type'] ?? null) === $category));

        return count($matches) === 1 ? (string) $matches[0]['key'] : null;
    }
}
