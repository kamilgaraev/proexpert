<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\Models\Organization;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;

final readonly class DompdfAssistantReportPdfWriter implements AssistantReportPdfWriterInterface
{
    public function __construct(
        private AssistantGeneratedReportStorage $reportStorage,
    ) {}

    public function store(
        string $view,
        array $data,
        Organization $organization,
        User $user,
        string $filenamePrefix,
    ): array {
        $pdf = Pdf::loadView($view, $data);
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $content = $pdf->output();
        $filename = $this->filename($filenamePrefix);

        return $this->reportStorage->storePdf($content, $filename, $organization, $user);
    }

    private function filename(string $filenamePrefix): string
    {
        $prefix = preg_replace('/[^a-z0-9_]+/i', '_', $filenamePrefix) ?: 'rag_report';
        $prefix = trim($prefix, '_');

        return ($prefix !== '' ? $prefix : 'rag_report').'_'.time().'.pdf';
    }
}
