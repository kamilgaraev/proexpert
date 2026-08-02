<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

final class ProjectModelSourceVersionPinning
{
    /** @param list<array<string,mixed>|\stdClass> $evidence @return array<int,array{source_version:string,pages:array<int,true>}> */
    public function references(array $evidence): array
    {
        $references = [];
        foreach ($evidence as $item) {
            $item = is_array($item) ? $item : get_object_vars($item);
            $locator = $this->array($item['locator'] ?? null);
            $id = filter_var($locator['document_id'] ?? null, FILTER_VALIDATE_INT);
            $version = $item['evidence_source_version'] ?? null;
            if ($id === false || $id < 1 || ! is_string($version) || preg_match('/^sha256:[a-f0-9]{64}$/', $version) !== 1) continue;
            $page = filter_var($locator['page'] ?? $locator['unit_index'] ?? null, FILTER_VALIDATE_INT);
            $references[$id] ??= ['source_version' => $version, 'pages' => []];
            if ($references[$id]['source_version'] !== $version) continue;
            if ($page !== false && $page > 0) $references[$id]['pages'][$page] = true;
        }

        return $references;
    }

    /** @param list<array<string,mixed>|\stdClass> $documents @param array<int,array{source_version:string,pages:array<int,true>}> $references @return list<array<string,mixed>|\stdClass> */
    public function documents(array $documents, array $references): array
    {
        return array_values(array_filter($documents, function (array|\stdClass $row) use ($references): bool {
            $row = is_array($row) ? $row : get_object_vars($row);
            $id = (int) ($row['id'] ?? 0);
            return isset($references[$id]) && hash_equals($references[$id]['source_version'], (string) ($row['source_version'] ?? ''));
        }));
    }

    /** @param list<array<string,mixed>|\stdClass> $sheets @param array<int,array{source_version:string,pages:array<int,true>}> $references @return list<array<string,mixed>|\stdClass> */
    public function sheets(array $sheets, array $references): array
    {
        return array_values(array_filter($sheets, function (array|\stdClass $row) use ($references): bool {
            $row = is_array($row) ? $row : get_object_vars($row);
            $documentId = (int) ($row['document_id'] ?? 0);
            $page = (int) ($row['page_number'] ?? 0);
            return isset($references[$documentId]['pages'][$page])
                && hash_equals($references[$documentId]['source_version'], (string) ($row['source_version'] ?? ''));
        }));
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        if (is_array($value)) return $value;
        $decoded = is_string($value) ? json_decode($value, true) : null;
        return is_array($decoded) ? $decoded : [];
    }
}
