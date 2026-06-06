<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Services;

use App\BusinessModules\Features\DesignManagement\Enums\DesignFileFormatEnum;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;
use ZipArchive;

final class DesignDocumentMetadataExtractor
{
    public function inspect(UploadedFile $file): array
    {
        $format = $this->formatFromName($file->getClientOriginalName());
        $path = $file->getRealPath();
        $metadata = [
            'file_format' => $format,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => (int) $file->getSize(),
            'sha256' => $path && is_file($path) ? hash_file('sha256', $path) : null,
            'page_count' => null,
            'sheet_count' => null,
            'details' => [],
        ];

        if (!$path || !is_file($path)) {
            return $metadata;
        }

        if (in_array($format, [DesignFileFormatEnum::PDF->value, DesignFileFormatEnum::PDF_A->value], true)) {
            return $this->withPdfMetadata($path, $metadata);
        }

        if (in_array($format, [DesignFileFormatEnum::XLSX->value, DesignFileFormatEnum::ODS->value], true)) {
            return $this->withSpreadsheetMetadata($path, $metadata);
        }

        if ($format === DesignFileFormatEnum::ZIP->value) {
            return $this->withZipMetadata($path, $metadata);
        }

        return $metadata;
    }

    public function formatFromName(string $name): string
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return DesignFileFormatEnum::PDF->value;
        }

        return in_array($extension, DesignFileFormatEnum::values(), true)
            ? $extension
            : DesignFileFormatEnum::ZIP->value;
    }

    private function withPdfMetadata(string $path, array $metadata): array
    {
        if (!class_exists(Parser::class)) {
            return $metadata;
        }

        try {
            $parser = new Parser();
            $document = $parser->parseFile($path);
            $metadata['page_count'] = count($document->getPages());
            $metadata['details']['pdf_metadata'] = $document->getDetails();
        } catch (\Throwable) {
            $metadata['details']['pdf_parse_failed'] = true;
        }

        return $metadata;
    }

    private function withSpreadsheetMetadata(string $path, array $metadata): array
    {
        if (!class_exists(IOFactory::class)) {
            return $metadata;
        }

        try {
            $spreadsheet = IOFactory::load($path);
            $metadata['sheet_count'] = $spreadsheet->getSheetCount();
            $metadata['details']['worksheet_titles'] = $spreadsheet->getSheetNames();
            $spreadsheet->disconnectWorksheets();
        } catch (\Throwable) {
            $metadata['details']['spreadsheet_parse_failed'] = true;
        }

        return $metadata;
    }

    private function withZipMetadata(string $path, array $metadata): array
    {
        if (!class_exists(ZipArchive::class)) {
            return $metadata;
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return $metadata;
        }

        $entries = [];
        $limit = min($zip->numFiles, 50);

        for ($index = 0; $index < $limit; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name)) {
                $entries[] = $name;
            }
        }

        $metadata['details']['entries_count'] = $zip->numFiles;
        $metadata['details']['entries_sample'] = $entries;
        $zip->close();

        return $metadata;
    }
}
