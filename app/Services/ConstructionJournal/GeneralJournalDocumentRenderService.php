<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;

final class GeneralJournalDocumentRenderService
{
    private const MAX_SNAPSHOT_BYTES = 2097152;
    private const MAX_PDF_BYTES = 26214400;
    private const MAX_PAGES = 500;
    private const CELL_CHUNK_LENGTH = 220;

    public function html(array $snapshot): string
    {
        $this->validate($snapshot);

        return view('construction-journal.general-document', [
            'definition' => GeneralJournalDocumentDefinition::class,
            'journal' => (array) $snapshot['journal'],
            'header' => $this->header($snapshot),
            'representatives' => (array) ($snapshot['profile']['representatives'] ?? []),
            'titleChanges' => array_values((array) ($snapshot['profile']['title_changes'] ?? [])),
            'sections' => $this->rows($snapshot['sections']),
            'revision' => (int) $snapshot['revision'],
            'correctionReason' => $snapshot['correction_reason'] ?? null,
        ])->render();
    }

    public function renderSnapshot(array $snapshot): string
    {
        $pdf = Pdf::loadHTML($this->html($snapshot))->setPaper('a4', 'portrait');
        $dompdf = $pdf->getDomPDF();
        $dompdf->getOptions()->setIsRemoteEnabled(false);
        $dompdf->getOptions()->setIsPhpEnabled(false);
        $bytes = $pdf->output();

        if ($dompdf->getCanvas()->get_page_number() > self::MAX_PAGES) {
            throw new DomainException('general_journal_too_many_pages');
        }

        if (strlen($bytes) > self::MAX_PDF_BYTES) {
            throw new DomainException('general_journal_pdf_too_large');
        }

        return $bytes;
    }

    private function validate(array $snapshot): void
    {
        if (($snapshot['template_version'] ?? null) !== GeneralJournalDocumentDefinition::TEMPLATE_VERSION) {
            throw new DomainException('unsupported_general_journal_template');
        }

        if (strlen(serialize($snapshot)) > self::MAX_SNAPSHOT_BYTES) {
            throw new DomainException('general_journal_snapshot_too_large');
        }

        if (!isset($snapshot['journal'], $snapshot['profile'], $snapshot['sections']) || !is_array($snapshot['sections'])) {
            throw new DomainException('invalid_general_journal_snapshot');
        }

        $rowCount = 0;
        foreach (GeneralJournalDocumentDefinition::sections() as $number => $_section) {
            $rows = $snapshot['sections'][$number] ?? [];
            if (!is_array($rows)) {
                throw new DomainException('invalid_general_journal_section');
            }
            $rowCount += count($rows);
        }

        if ($rowCount > self::MAX_PAGES * 10) {
            throw new DomainException('general_journal_too_many_pages');
        }
    }

    private function header(array $snapshot): array
    {
        $journal = (array) $snapshot['journal'];
        $source = (array) ($snapshot['profile']['header'] ?? []);
        $source['journal_number'] = $journal['number'] ?? ($source['journal_number'] ?? null);
        $source['project_name'] = $journal['project_name'] ?? ($source['project_name'] ?? null);
        $source['project_address'] = $journal['project_address'] ?? ($source['project_address'] ?? null);
        $source['journal_period_start'] = $journal['start_date'] ?? ($source['journal_period_start'] ?? null);
        $source['journal_period_end'] = $journal['end_date'] ?? ($source['journal_period_end'] ?? null);

        $header = [];
        foreach (array_keys(GeneralJournalDocumentDefinition::headerFields()) as $key) {
            $value = $source[$key] ?? null;
            $header[$key] = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $header;
    }

    private function rows(array $sections): array
    {
        $prepared = [];
        foreach (GeneralJournalDocumentDefinition::sections() as $number => $section) {
            $prepared[$number] = [];
            foreach (array_values((array) ($sections[$number] ?? [])) as $sourceIndex => $row) {
                $row = is_array($row) ? $row : [];
                $chunks = [];
                foreach ($section['columns'] as $key => $_label) {
                    $value = $row[$key] ?? '';
                    $value = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $chunks[$key] = $this->split($value);
                }

                $height = max(array_map('count', $chunks) ?: [1]);
                for ($index = 0; $index < $height; $index++) {
                    $prepared[$number][] = [
                        '_number' => (string) ($sourceIndex + 1),
                        '_continuation' => $index > 0,
                        ...array_map(static fn (array $values): string => $values[$index] ?? '', $chunks),
                    ];
                }
            }
        }

        return $prepared;
    }

    private function split(string $value): array
    {
        if ($value === '') {
            return [''];
        }

        if (! function_exists('mb_strlen')) {
            return preg_split('/\s+(?=\S)/u', wordwrap($value, self::CELL_CHUNK_LENGTH, "\n", true), -1, PREG_SPLIT_NO_EMPTY) ?: [''];
        }

        $parts = [];
        while (mb_strlen($value) > self::CELL_CHUNK_LENGTH) {
            $candidate = mb_substr($value, 0, self::CELL_CHUNK_LENGTH);
            $cut = mb_strrpos($candidate, ' ');
            if ($cut === false || $cut < (int) (self::CELL_CHUNK_LENGTH * 0.6)) {
                $cut = self::CELL_CHUNK_LENGTH;
            }
            $parts[] = trim(mb_substr($value, 0, $cut));
            $value = ltrim(mb_substr($value, $cut));
        }
        $parts[] = $value;

        return $parts;
    }
}
