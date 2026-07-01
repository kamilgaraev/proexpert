<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Services\Storage\FileService;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

final readonly class DompdfAssistantReportPdfWriter implements AssistantReportPdfWriterInterface
{
    public function __construct(
        private FileService $fileService
    ) {}

    public function store(string $view, array $data, Organization $organization, string $filenamePrefix): array
    {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $content = $pdf->output();
        $filename = $this->filename($filenamePrefix);
        $path = $this->fileService->putContent($content, 'reports', $filename, 'private', $organization);

        if (! is_string($path)) {
            throw new RuntimeException('Не удалось сохранить отчет.');
        }

        $expiresAt = now()->addHours(24);
        $url = $this->fileService->temporaryUrl($path, 1440, $organization);

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Не удалось сформировать ссылку на отчет.');
        }

        return [
            'pdf_url' => $url,
            'filename' => $filename,
            'storage_disk' => 's3',
            'storage_path' => $path,
            'expires_at' => $expiresAt->toIso8601String(),
            'size' => strlen($content),
        ];
    }

    private function filename(string $filenamePrefix): string
    {
        $prefix = preg_replace('/[^a-z0-9_]+/i', '_', $filenamePrefix) ?: 'rag_report';
        $prefix = trim($prefix, '_');

        return ($prefix !== '' ? $prefix : 'rag_report').'_'.time().'.pdf';
    }
}
