<?php

namespace App\Services\Export;

use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Exception;

class PdfExporterService
{
    /**
     * Генерирует и возвращает PDF файл для скачивания.
     * 
     * @param string $view Блейд-шаблон
     * @param array $data Данные для шаблона
     * @param string $filename Имя файла
     * @param string $paper Формат бумаги (a4)
     * @param string $orientation Ориентация (portrait/landscape)
     * @return StreamedResponse
     */
    public function streamDownload(
        string $view,
        array $data,
        string $filename,
        string $paper = 'a4',
        string $orientation = 'portrait'
    ): StreamedResponse {
        try {
            $pdf = Pdf::loadView($view, $data);
            $pdf->setPaper($paper, $orientation);
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ]);

            return response()->streamDownload(function () use ($pdf) {
                echo $pdf->output();
            }, $filename);
        } catch (Exception $e) {
            Log::error('[PdfExporterService] Ошибка при генерации PDF:', [
                'exception' => $e->getMessage(),
                'view' => $view,
                'filename' => $filename
            ]);
            throw $e;
        }
    }
}
