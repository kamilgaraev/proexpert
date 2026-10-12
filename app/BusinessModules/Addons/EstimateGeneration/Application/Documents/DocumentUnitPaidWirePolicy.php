<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use Illuminate\Database\Eloquent\Builder;

/** Only exact, locally parsed artifact contracts bypass a paid-wire approval. */
final class DocumentUnitPaidWirePolicy
{
    public static function requiresPaidWire(DocumentUnitType $type, array $locator): bool
    {
        if ($type === DocumentUnitType::CadDrawing) {
            return false;
        }
        if ($type === DocumentUnitType::TextPage && ($locator['content_type'] ?? null) === 'text/plain') {
            return false;
        }
        if ($type === DocumentUnitType::PdfPage && ($locator['content_type'] ?? null) === 'application/vnd.most.pdf-page+json') {
            return false;
        }
        if ($type !== DocumentUnitType::SpreadsheetSheet
            || (array_key_exists('source_kind', $locator) && $locator['source_kind'] !== 'spreadsheet')) {
            return true;
        }

        return ! ((($locator['content_type'] ?? null) === 'application/json' && ($locator['artifact_kind'] ?? null) === 'spreadsheet_sheet'
                && ($locator['artifact_schema_version'] ?? null) === 1)
            || (($locator['content_type'] ?? null) === 'application/vnd.most.spreadsheet-sheet+json'
                && ! array_key_exists('artifact_kind', $locator) && ! array_key_exists('artifact_schema_version', $locator)));
    }

    /** Mirrors the processor contract above for bounded SQL queue selection. */
    public static function freeEligibility(Builder $query): void
    {
        $query->where('unit_type', 'cad_drawing')
            ->orWhere(static fn (Builder $text) => $text->where('unit_type', 'text_page')->where('locator->content_type', 'text/plain'))
            ->orWhere(static fn (Builder $pdf) => $pdf->where('unit_type', 'pdf_page')->where('locator->content_type', 'application/vnd.most.pdf-page+json'))
            ->orWhere(static fn (Builder $sheet) => $sheet->where('unit_type', 'spreadsheet_sheet')
                ->whereRaw("(NOT (locator ?? 'source_kind') OR locator->>'source_kind' = 'spreadsheet')")
                ->where(static fn (Builder $artifact) => $artifact->where(static fn (Builder $canonical) => $canonical
                    ->where('locator->content_type', 'application/json')->where('locator->artifact_kind', 'spreadsheet_sheet')
                    ->whereRaw("locator->'artifact_schema_version' = '1'::jsonb"))
                    ->orWhere(static fn (Builder $legacy) => $legacy->where('locator->content_type', 'application/vnd.most.spreadsheet-sheet+json')
                        ->whereRaw("NOT (locator ?? 'artifact_kind') AND NOT (locator ?? 'artifact_schema_version')"))));
    }
}
