<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Cheap admission before storage. Parsing and page limits still require their own bounded reader. */
final class DocumentFileAdmission
{
    public function uploaded(UploadedFile $file, EffectiveEstimateGenerationSettings $settings): void
    {
        $path = $file->getRealPath();
        $size = $file->getSize();
        $extension = strtolower($file->getClientOriginalExtension());
        if (! is_string($path) || ! $file->isValid() || ! is_int($size) || $size < 1 || ! $settings->allowsFormat($extension)) {
            $this->reject();
        }
        $this->path($path, $extension, $size);
    }

    private function path(string $path, string $extension, int $size): void
    {
        $header = file_get_contents($path, false, null, 0, 4096);
        try {
            if (! is_string($header)) {
                throw new InvalidArgumentException('document_signature_unavailable');
            }
            $format = $this->signatureFormat($header, $extension);
            if ($size > $this->maximumBytes($format)) {
                throw new InvalidArgumentException('document_size_exceeded');
            }
            if ($format === 'xlsx') {
                $zip = new \ZipArchive;
                if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
                    throw new InvalidArgumentException('document_archive_invalid');
                }
                try {
                    if ($zip->numFiles > 10000 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                        throw new InvalidArgumentException('document_archive_invalid');
                    }
                    $expanded = 0;
                    for ($index = 0; $index < $zip->numFiles; $index++) {
                        $entry = $zip->statIndex($index);
                        if (! is_array($entry) || ($entry['size'] ?? 0) > 50 * 1024 * 1024) {
                            throw new InvalidArgumentException('document_archive_invalid');
                        }
                        $expanded += $entry['size'];
                        if ($expanded > 200 * 1024 * 1024) {
                            throw new InvalidArgumentException('document_archive_invalid');
                        }
                    }
                } finally {
                    $zip->close();
                }
            }
        } catch (InvalidArgumentException) {
            $this->reject();
        }
    }

    public function stored(EstimateGenerationDocument $document, EffectiveEstimateGenerationSettings $settings): void
    {
        $extension = strtolower(pathinfo((string) $document->filename, PATHINFO_EXTENSION));
        $format = match (strtolower((string) $document->mime_type)) {
            'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls', 'application/dxf', 'image/vnd.dxf' => 'dxf',
            'application/dwg', 'image/vnd.dwg', 'application/acad' => 'dwg',
            default => null,
        };
        $nameFormat = $extension === 'jpeg' ? 'jpg' : $extension;
        if (($format !== null && $nameFormat !== $format) || ! $settings->allowsFormat($extension)
            || ! is_int($document->file_size_bytes) || $document->file_size_bytes < 1
            || ! in_array($nameFormat, ['pdf', 'jpg', 'png', 'xlsx', 'xls', 'dwg', 'dxf'], true)
            || $document->file_size_bytes > $this->maximumBytes($nameFormat)) {
            $this->reject();
        }
        $source = app(DocumentSourceManifestStorage::class)->open($document, DocumentSourceVersion::fromDocument($document));
        try {
            $this->path($source->path(), $extension, $source->bytes());
        } finally {
            $source->close();
        }
    }

    public function signatureFormat(string $header, string $extension): string
    {
        $format = match (true) {
            preg_match('/\A(?:\xEF\xBB\xBF)?[\x20\r\n\t]*%PDF-[0-9]\.[0-9]/', $header) === 1 => 'pdf',
            str_starts_with($header, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($header, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($header, "PK\x03\x04") => 'xlsx',
            str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") => 'xls',
            preg_match('/\AAC10[0-9]{2}/', $header) === 1 => 'dwg',
            str_starts_with($header, "AutoCAD Binary DXF\r\n\x1A\x00") || preg_match('/(?:\A|\r?\n)[ \t]*0[ \t]*\r?\n[ \t]*SECTION[ \t]*(?:\r?\n|\z)/', $header) === 1 => 'dxf',
            default => null,
        };
        if ($format === null || $format !== ($extension === 'jpeg' ? 'jpg' : $extension)) {
            throw new InvalidArgumentException('document_signature_format_mismatch');
        }

        return $format;
    }

    public function maximumBytes(string $format): int
    {
        return match ($format) {
            'pdf' => min(200 * 1024 * 1024, max(1, (int) config('estimate-generation.ocr.max_pdf_file_bytes', 200 * 1024 * 1024))),
            'xlsx', 'xls' => min(50 * 1024 * 1024, max(1, (int) config('estimate-generation.ocr.max_spreadsheet_file_bytes', 50 * 1024 * 1024))),
            'dwg', 'dxf' => min(max(1, (int) config('estimate-generation.ocr.max_cad_file_bytes', 200 * 1024 * 1024)),
                max(1, (int) config('estimate-generation.vision.cad_runtime.max_input_bytes', 50 * 1024 * 1024))),
            'jpg', 'png' => min(max(1, (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024)),
                max(1, (int) config('estimate-generation.vision.preprocessing.max_bytes', 20_000_000))),
            default => throw new InvalidArgumentException('document_format_unsupported'),
        };
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['files' => trans_message('estimate_evaluation.document_admission_failed')]);
    }
}
