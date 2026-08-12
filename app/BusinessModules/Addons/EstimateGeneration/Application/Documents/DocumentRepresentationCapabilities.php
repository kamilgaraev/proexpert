<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class DocumentRepresentationCapabilities
{
    private const REQUIRED = [
        'pdf' => ['text_spans', 'vectors', 'page_render', 'source_coordinates'],
        'image' => ['original_raster', 'ocr_spans', 'image_coordinates'],
        'cad' => ['layers', 'blocks', 'polylines', 'dimensions', 'texts', 'sheet_render', 'source_coordinates'],
        'xlsx' => ['sheets', 'cells', 'formulas', 'merges', 'table_render', 'source_coordinates'],
    ];

    private function __construct(
        public string $format,
        private array $statuses,
    ) {}

    public static function fromArray(string $format, array $statuses): self
    {
        $required = self::REQUIRED[$format] ?? null;
        $provided = array_keys($statuses);
        if ($required === null
            || count($provided) !== count($required)
            || array_diff($required, $provided) !== []
            || array_diff($provided, $required) !== []) {
            throw new InvalidArgumentException('Document capability contract is not canonical.');
        }

        $canonical = [];
        foreach ($required as $capability) {
            $status = $statuses[$capability];
            if ($status === 'available') {
                $canonical[$capability] = $status;

                continue;
            }
            if (! is_string($status)
                || preg_match('/^unavailable:[a-z][a-z0-9_]{2,80}$/D', $status) !== 1) {
                throw new InvalidArgumentException('Document capability status is invalid.');
            }
            $canonical[$capability] = $status;
        }

        return new self($format, $canonical);
    }

    public function toArray(): array
    {
        return $this->statuses;
    }

    public function limitations(): array
    {
        $limitations = [];
        foreach ($this->statuses as $capability => $status) {
            if ($status !== 'available') {
                $limitations[] = new DocumentRepresentationLimitation(
                    $capability,
                    substr($status, strlen('unavailable:')),
                );
            }
        }

        return $limitations;
    }

    public function assertAvailable(string $capability): void
    {
        $status = $this->statuses[$capability] ?? null;
        if ($status === 'available') {
            return;
        }
        if (! is_string($status)) {
            throw new InvalidArgumentException('Unknown document capability.');
        }

        throw new DocumentManifestNeedsReview('document_native_capability_unavailable', [
            'capability' => $capability,
            'reason' => substr($status, strlen('unavailable:')),
        ]);
    }
}
