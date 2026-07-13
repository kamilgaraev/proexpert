<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

use App\Models\Organization;
use App\Services\Storage\FileService;

final readonly class GeometryReviewSourcePresenter
{
    private const ELEMENT_TYPES = ['room', 'wall', 'opening', 'dimension', 'axis', 'engineering_element', 'text'];

    public function __construct(private FileService $files) {}

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    public function present(array $row, int $organizationId, int $sessionId): ?array
    {
        $documentId = filter_var($row['document_id'] ?? null, FILTER_VALIDATE_INT);
        $pageId = filter_var($row['page_id'] ?? null, FILTER_VALIDATE_INT);
        $pageNumber = filter_var($row['page_number'] ?? null, FILTER_VALIDATE_INT);
        $width = filter_var($row['width'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($row['height'] ?? null, FILTER_VALIDATE_INT);
        $path = is_string($row['artifact_path'] ?? null) ? trim($row['artifact_path'], '/') : '';
        $contentType = $row['content_type'] ?? null;
        $prefix = sprintf(
            'org-%d/estimate-generation/sessions/%d/documents/%d/manifests/',
            $organizationId,
            $sessionId,
            $documentId,
        );
        if ($documentId === false || $documentId < 1 || $pageId === false || $pageId < 1
            || $pageNumber === false || $pageNumber < 1 || $width === false || $width < 1
            || $height === false || $height < 1 || $contentType !== 'image/png'
            || ! str_starts_with($path, $prefix) || str_contains($path, '../')) {
            return null;
        }

        $organization = new Organization;
        $organization->forceFill(['id' => $organizationId]);
        $url = $this->files->temporaryUrl($path, 5, $organization, ['ResponseContentType' => 'image/png']);
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return [
            'document_id' => $documentId,
            'page_id' => $pageId,
            'page_number' => $pageNumber,
            'filename' => is_string($row['filename'] ?? null) ? $row['filename'] : '',
            'source_size' => [$width, $height],
            'image_url' => $url,
            'elements' => $this->elements($row['normalized_payload'] ?? []),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function elements(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $elements = is_array($payload)
            && is_array($payload['vision_analysis'] ?? null)
            && is_array($payload['vision_analysis']['elements'] ?? null)
                ? $payload['vision_analysis']['elements']
                : [];

        return array_values(array_filter(array_map(function (mixed $element): ?array {
            if (! is_array($element) || ! is_string($element['key'] ?? null)
                || ! in_array($element['type'] ?? null, self::ELEMENT_TYPES, true)
                || ! is_array($element['polygon'] ?? null)
                || ! is_numeric($element['confidence'] ?? null)
                || ! is_string($element['evidence_ref'] ?? null)) {
                return null;
            }
            $polygon = [];
            foreach ($element['polygon'] as $point) {
                if (! is_array($point) || ! array_is_list($point) || count($point) !== 2
                    || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                    return null;
                }
                $x = (float) $point[0];
                $y = (float) $point[1];
                if (! is_finite($x) || ! is_finite($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                    return null;
                }
                $polygon[] = [$x, $y];
            }
            if (count($polygon) < 2) {
                return null;
            }

            return [
                'key' => $element['key'],
                'type' => $element['type'],
                'label' => is_string($element['label'] ?? null) ? $element['label'] : null,
                'polygon' => $polygon,
                'confidence' => (float) $element['confidence'],
                'evidence_ref' => $element['evidence_ref'],
            ];
        }, $elements)));
    }
}
