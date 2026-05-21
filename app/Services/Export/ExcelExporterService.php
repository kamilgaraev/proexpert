<?php

namespace App\Services\Export;

use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Models\PersonalFile;
use App\Models\ReportFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Services\Logging\LoggingService;
use App\Services\Storage\OrganizationStoragePath;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Exception;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class ExcelExporterService
{
    protected LoggingService $logging;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
    }

    /**
     * Р“РµРЅРµСЂРёСЂСѓРµС‚ Рё РІРѕР·РІСЂР°С‰Р°РµС‚ StreamedResponse РґР»СЏ СЃРєР°С‡РёРІР°РЅРёСЏ Excel С„Р°Р№Р»Р°.
     * Р’ СЃР»СѓС‡Р°Рµ РѕС€РёР±РєРё Р»РѕРіРёСЂСѓРµС‚ Рё РІРѕР·РІСЂР°С‰Р°РµС‚ JSON-РѕС‚РІРµС‚ СЃ РѕС€РёР±РєРѕР№.
     *
     * @param string $filename РРјСЏ С„Р°Р№Р»Р° (СЃ .xlsx)
     * @param array $headers РњР°СЃСЃРёРІ Р·Р°РіРѕР»РѕРІРєРѕРІ РєРѕР»РѕРЅРѕРє
     * @param array|\Illuminate\Support\Collection $data РњР°СЃСЃРёРІ РґР°РЅРЅС‹С…
     * @return StreamedResponse|\Illuminate\Http\JsonResponse
     */
    public function streamDownload(
        string $filename,
        array $headers,
        $data
    ) {
        // BUSINESS: РќР°С‡Р°Р»Рѕ СЌРєСЃРїРѕСЂС‚Р° Excel - РІР°Р¶РЅР°СЏ С„СѓРЅРєС†РёРѕРЅР°Р»СЊРЅРѕСЃС‚СЊ РґР»СЏ РїРѕР»СЊР·РѕРІР°С‚РµР»РµР№
        $this->logging->business('excel.export.started', [
            'filename' => $filename,
            'headers_count' => count($headers),
            'data_count' => is_countable($data) ? count($data) : null,
            'export_format' => 'xlsx',
            'user_id' => Auth::id(),
            'organization_id' => request()->attributes->get('current_organization_id')
        ]);

        // TECHNICAL: Р”РµС‚Р°Р»Рё СЌРєСЃРїРѕСЂС‚Р° РґР»СЏ РґРёР°РіРЅРѕСЃС‚РёРєРё
        $this->logging->technical('excel.export.details', [
            'filename' => $filename,
            'headers' => $headers,
            'data_type' => gettype($data),
            'first_row_sample' => is_iterable($data) ? (is_array($data) ? ($data[0] ?? null) : (method_exists($data, 'first') ? $data->first() : null)) : null,
        ]);
        try {
            $response = new StreamedResponse(function () use ($headers, $data, $filename) {
                try {
                    // TECHNICAL: РќР°С‡Р°Р»Рѕ СЃРѕР·РґР°РЅРёСЏ Excel РґРѕРєСѓРјРµРЅС‚Р°
                    $this->logging->technical('excel.spreadsheet.creation.started', [
                        'filename' => $filename,
                        'columns_count' => count($headers)
                    ]);
                    $spreadsheet = new Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();

                    // РЇРІРЅРѕ Р·Р°РїРёСЃС‹РІР°РµРј Р·Р°РіРѕР»РѕРІРєРё РєРѕР»РѕРЅРѕРє
                    $colIndex = 0;
                    foreach ($headers as $header) {
                        $cell = Coordinate::stringFromColumnIndex($colIndex + 1) . '1';
                        $sheet->setCellValue($cell, $header);
                        $colIndex++;
                    }

                    // РЎС‚РёР»РёР·Р°С†РёСЏ Р·Р°РіРѕР»РѕРІРєРѕРІ
                    $headerStyle = [
                        'font' => [
                            'bold' => true,
                            'size' => 12,
                        ],
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'E3EAFD'],
                        ],
                        'alignment' => [
                            'horizontal' => Alignment::HORIZONTAL_CENTER,
                            'vertical' => Alignment::VERTICAL_CENTER,
                            'wrapText' => true,
                        ],
                        'borders' => [
                            'allBorders' => [
                                'borderStyle' => Border::BORDER_THIN,
                                'color' => ['rgb' => 'AAB2BD'],
                            ],
                        ],
                    ];
                    $colCount = count($headers);
                    $sheet->getStyle('A1:' . Coordinate::stringFromColumnIndex($colCount) . '1')->applyFromArray($headerStyle);
                    $sheet->getRowDimension(1)->setRowHeight(28);

                    // Р—Р°РїРёСЃСЊ РґР°РЅРЅС‹С… Рё СЃС‚РёР»РёР·Р°С†РёСЏ СЃС‚СЂРѕРє
                    $rowIndex = 2;
                    $rowLogged = 0;
                    foreach ($data as $rowArray) {
                        $colIndex = 0;
                        foreach ($rowArray as $value) {
                            $cell = Coordinate::stringFromColumnIndex($colIndex + 1) . $rowIndex;
                            $sheet->setCellValue($cell, $value);
                            // Р¤РѕСЂРјР°С‚РёСЂРѕРІР°РЅРёРµ С‡РёСЃРµР» Рё РґР°С‚
                            if (is_numeric($value) && $colIndex > 0) {
                                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
                                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                            }
                            if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$value)) {
                                $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('DD.MM.YYYY');
                                $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                            }
                            // РџСЂРёРјРµС‡Р°РЅРёСЏ вЂ” РїРµСЂРµРЅРѕСЃ СЃС‚СЂРѕРє
                            if ($colIndex === array_key_last($rowArray)) {
                                $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
                            }
                            $colIndex++;
                        }
                        // Р“СЂР°РЅРёС†С‹ РґР»СЏ РІСЃРµР№ СЃС‚СЂРѕРєРё
                        $sheet->getStyle('A' . $rowIndex . ':' . Coordinate::stringFromColumnIndex($colCount) . $rowIndex)
                            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->setColor(new Color('AAB2BD'));
                        $rowIndex++;
                    }

                    // РђРІС‚РѕС€РёСЂРёРЅР° РґР»СЏ РІСЃРµС… РєРѕР»РѕРЅРѕРє
                    for ($c = 0; $c < $colCount; $c++) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c + 1))->setAutoSize(true);
                    }

                    // Р—Р°РјРѕСЂРѕР·РєР° Р·Р°РіРѕР»РѕРІРєР°
                    $sheet->freezePane('A2');

                    // TECHNICAL: Р—Р°РїРёСЃСЊ Excel С„Р°Р№Р»Р° РІ РїРѕС‚РѕРє
                    $this->logging->technical('excel.writer.started', [
                        'filename' => $filename,
                        'total_rows' => $rowIndex - 2,
                        'total_columns' => count($headers)
                    ]);
                    
                    $writer = new Xlsx($spreadsheet);
                    $stream = fopen('php://temp', 'w+b');

                    if ($stream === false) {
                        $writer->save('php://output');
                    } else {
                        $writer->save($stream);
                        rewind($stream);
                        $binaryContent = stream_get_contents($stream);
                        fclose($stream);

                        if ($binaryContent !== false) {
                            echo $binaryContent;
                            $this->storeReportInPersonalFiles($filename, $binaryContent);
                        }
                    }
                    
                    // BUSINESS: Excel СЌРєСЃРїРѕСЂС‚ СѓСЃРїРµС€РЅРѕ Р·Р°РІРµСЂС€С‘РЅ
                    $this->logging->business('excel.export.completed', [
                        'filename' => $filename,
                        'total_rows' => $rowIndex - 2,
                        'total_columns' => count($headers),
                        'export_format' => 'xlsx',
                        'user_id' => Auth::id()
                    ]);
                } catch (Exception $e) {
                    // TECHNICAL: РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РіРµРЅРµСЂР°С†РёРё Excel
                    $this->logging->technical('excel.generation.exception', [
                        'filename' => $filename,
                        'exception_class' => get_class($e),
                        'exception_message' => $e->getMessage(),
                        'exception_file' => $e->getFile(),
                        'exception_line' => $e->getLine(),
                        'headers_count' => count($headers),
                        'data_count' => is_countable($data) ? count($data) : null
                    ], 'error');

                    // BUSINESS: РќРµСѓРґР°С‡РЅС‹Р№ СЌРєСЃРїРѕСЂС‚ Excel - РІР»РёСЏРµС‚ РЅР° РїРѕР»СЊР·РѕРІР°С‚РµР»СЊСЃРєРёР№ РѕРїС‹С‚
                    $this->logging->business('excel.export.failed', [
                        'filename' => $filename,
                        'export_format' => 'xlsx',
                        'failure_reason' => 'generation_exception',
                        'error_message' => $e->getMessage(),
                        'user_id' => Auth::id()
                    ], 'error');
                    
                    // РќРµ РІС‹РІРѕРґРёРј JSON РІ РїРѕС‚РѕРє, С‚Р°Рє РєР°Рє СЌС‚Рѕ РїРѕСЂС‚РёС‚ Excel С„Р°Р№Р»
                    // Р’РјРµСЃС‚Рѕ СЌС‚РѕРіРѕ СЃРѕР·РґР°РµРј РїСѓСЃС‚РѕР№ Excel С„Р°Р№Р» СЃ СЃРѕРѕР±С‰РµРЅРёРµРј РѕР± РѕС€РёР±РєРµ
                    $errorSpreadsheet = new Spreadsheet();
                    $errorSheet = $errorSpreadsheet->getActiveSheet();
                    $errorSheet->setCellValue('A1', 'РћС€РёР±РєР° РїСЂРё РіРµРЅРµСЂР°С†РёРё РѕС‚С‡РµС‚Р°');
                    $errorSheet->setCellValue('A2', $e->getMessage());
                    $errorWriter = new Xlsx($errorSpreadsheet);
                    $errorWriter->save('php://output');
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё StreamedResponse:', [
                'exception' => $e,
                'headers' => $headers,
                'first_row' => is_iterable($data) ? (is_array($data) ? ($data[0] ?? null) : (method_exists($data, 'first') ? $data->first() : null)) : null,
                'data_count' => is_countable($data) ? count($data) : null,
            ]);
            return \App\Http\Responses\AdminResponse::fromPayload([
                'error' => 'РћС€РёР±РєР° РїСЂРё СЌРєСЃРїРѕСЂС‚Рµ РІ Excel',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function storeReportInPersonalFiles(string $filename, string $binaryContent, bool $registerReportFile = true): bool
    {
        $user = Auth::user();

        if (!$user instanceof \App\Models\User) {
            return false;
        }

        try {
            $organization = $user->currentOrganization;
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $storedName = (string) Str::uuid() . ($extension ? '.' . $extension : '');
            $personalPath = $user->id . '/reports/' . $storedName;
            $reportPath = $organization
                ? OrganizationStoragePath::forOrganization($organization->id, 'reports/' . $storedName)
                : $personalPath;
            $path = $registerReportFile ? $reportPath : $personalPath;

            $stored = app(\App\Services\Storage\FileService::class)
                ->disk($organization)
                ->put($path, $binaryContent);

            if ($stored === false) {
                Log::warning('[ExcelExporterService] Report file storage returned false', [
                    'filename' => $filename,
                    'user_id' => $user->id,
                    'path' => $path,
                ]);

                return false;
            }

            PersonalFile::query()->create([
                'user_id' => $user->id,
                'path' => $path,
                'filename' => $filename,
                'size' => strlen($binaryContent),
                'is_folder' => false,
            ]);

            if ($registerReportFile && $organization) {
                ReportFile::query()->updateOrCreate(
                    ['path' => $path],
                    [
                        'organization_id' => $organization->id,
                        'type' => $extension ?: 'reports',
                        'filename' => $filename,
                        'name' => $filename,
                        'size' => strlen($binaryContent),
                        'expires_at' => now()->addYear(),
                        'user_id' => $user->id,
                    ]
                );
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('[ExcelExporterService] Failed to store report in personal files', [
                'filename' => $filename,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * РЎРѕС…СЂР°РЅСЏРµС‚ Excel С„Р°Р№Р» РЅР° РґРёСЃРє.
     *
     * @param array|\Illuminate\Support\Collection $data РњР°СЃСЃРёРІ РґР°РЅРЅС‹С…
     * @param array $headers РњР°СЃСЃРёРІ Р·Р°РіРѕР»РѕРІРєРѕРІ РєРѕР»РѕРЅРѕРє
     * @param string $filePath РџСѓС‚СЊ Рє С„Р°Р№Р»Сѓ РґР»СЏ СЃРѕС…СЂР°РЅРµРЅРёСЏ
     * @return void
     */
    public function saveToFile($data, array $headers, string $filePath): void
    {
        try {
            Log::info('[ExcelExporterService] РЎРѕС…СЂР°РЅРµРЅРёРµ Excel С„Р°Р№Р»Р° РЅР° РґРёСЃРє', [
                'file_path' => $filePath,
                'headers_count' => count($headers),
                'data_count' => is_countable($data) ? count($data) : null,
            ]);

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // Р—Р°РїРёСЃС‹РІР°РµРј Р·Р°РіРѕР»РѕРІРєРё
            $colIndex = 0;
            foreach ($headers as $header) {
                $cell = Coordinate::stringFromColumnIndex($colIndex + 1) . '1';
                $sheet->setCellValue($cell, $header);
                $colIndex++;
            }

            // Р—Р°РїРёСЃС‹РІР°РµРј РґР°РЅРЅС‹Рµ
            $rowIndex = 2;
            $preparedData = $this->prepareDataForExport($data, []);
            foreach ($preparedData['data'] as $rowArray) {
                $colIndex = 0;
                foreach ($rowArray as $value) {
                    $cell = Coordinate::stringFromColumnIndex($colIndex + 1) . $rowIndex;
                    $sheet->setCellValue($cell, $value);
                    $colIndex++;
                }
                $rowIndex++;
            }

            // РЎРѕС…СЂР°РЅСЏРµРј С„Р°Р№Р»
            $writer = new Xlsx($spreadsheet);
            $writer->save($filePath);

            Log::info('[ExcelExporterService] Excel С„Р°Р№Р» СѓСЃРїРµС€РЅРѕ СЃРѕС…СЂР°РЅРµРЅ', [
                'file_path' => $filePath,
                'rows_count' => $rowIndex - 2,
            ]);

        } catch (Exception $e) {
            Log::error('[ExcelExporterService] РћС€РёР±РєР° РїСЂРё СЃРѕС…СЂР°РЅРµРЅРёРё Excel С„Р°Р№Р»Р°', [
                'file_path' => $filePath,
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    /**
     * Р“РѕС‚РѕРІРёС‚ РґР°РЅРЅС‹Рµ РґР»СЏ СЌРєСЃРїРѕСЂС‚Р° РІ Excel.
     *
     * @param array|\Illuminate\Support\Collection $rawData
     * @param array $columnMapping
     * @return array
     */
    public function prepareDataForExport($rawData, array $columnMapping): array
    {
        $excelHeaders = array_keys($columnMapping);
        $dataKeys = array_values($columnMapping);

        $exportData = [];
        if (is_iterable($rawData)) {
            foreach($rawData as $item) {
                $rowData = [];
                foreach ($dataKeys as $dataKey) {
                    $value = Arr::get($item, $dataKey, '');
                    if ($value instanceof \Carbon\Carbon) {
                        $value = $value->format('d.m.Y H:i:s');
                    } elseif (is_float($value)) {
                        $value = number_format($value, 2, ',', '');
                    } elseif (is_bool($value)) {
                        $value = $value ? 'Р”Р°' : 'РќРµС‚';
                    }
                    $rowData[] = $value;
                }
                $exportData[] = $rowData;
            }
        }
        return [
            'headers' => $excelHeaders,
            'data' => $exportData
        ];
    }

    /**
     * РЎРѕР·РґР°РµС‚ РјРЅРѕРіРѕСЃС‚СЂР°РЅРёС‡РЅС‹Р№ Excel РѕС‚С‡РµС‚ РїРѕ Р°РєС‚РёРІРЅРѕСЃС‚Рё РїСЂРѕСЂР°Р±РѕРІ.
     * РљР°Р¶РґС‹Р№ РїСЂРѕСЂР°Р± РїРѕР»СѓС‡Р°РµС‚ РѕС‚РґРµР»СЊРЅС‹Р№ Р»РёСЃС‚ СЃ РґРµС‚Р°Р»СЊРЅРѕР№ РёРЅС„РѕСЂРјР°С†РёРµР№.
     */
    public function streamForemanActivityReport(
        string $filename,
        array $foremanData,
        array $materialLogs,
        array $completedWorks
    ) {
        Log::info('[ExcelExporterService] РќР°С‡Р°Р»Рѕ СЌРєСЃРїРѕСЂС‚Р° РѕС‚С‡РµС‚Р° РїРѕ Р°РєС‚РёРІРЅРѕСЃС‚Рё РїСЂРѕСЂР°Р±РѕРІ', [
            'filename' => $filename,
            'foreman_count' => count($foremanData),
        ]);

        try {
            $response = new StreamedResponse(function () use ($foremanData, $materialLogs, $completedWorks) {
                try {
                    $spreadsheet = new Spreadsheet();
                    
                    // РЈРґР°Р»СЏРµРј Р»РёСЃС‚ РїРѕ СѓРјРѕР»С‡Р°РЅРёСЋ, СЃРѕР·РґР°РґРёРј СЃРІРѕРё
                    $spreadsheet->removeSheetByIndex(0);

                    foreach ($foremanData as $index => $foreman) {
                        $sheetName = mb_substr($foreman['user_name'], 0, 30);
                        $sheet = $spreadsheet->createSheet($index);
                        $sheet->setTitle($sheetName);

                        // Р—Р°РіРѕР»РѕРІРѕРє РѕС‚С‡РµС‚Р°
                        $sheet->setCellValue('A1', 'РћРўР§Р•Рў РџРћ РђРљРўРР’РќРћРЎРўР РџР РћР РђР‘Рђ');
                        $sheet->mergeCells('A1:F1');
                        $sheet->getStyle('A1')->applyFromArray([
                            'font' => ['bold' => true, 'size' => 16],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'B8CCE4']],
                        ]);

                        // РРЅС„РѕСЂРјР°С†РёСЏ Рѕ РїСЂРѕСЂР°Р±Рµ
                        $sheet->setCellValue('A3', 'Р¤РРћ РїСЂРѕСЂР°Р±Р°:');
                        $sheet->setCellValue('B3', $foreman['user_name']);
                        $sheet->setCellValue('A4', 'Email:');
                        $sheet->setCellValue('B4', $foreman['user_email']);
                        $sheet->setCellValue('A5', 'РЎС‚Р°С‚СѓСЃ:');
                        $sheet->setCellValue('B5', $foreman['is_active'] ? 'РђРєС‚РёРІРµРЅ' : 'РќРµР°РєС‚РёРІРµРЅ');
                        $sheet->setCellValue('A6', 'РџРѕСЃР»РµРґРЅСЏСЏ Р°РєС‚РёРІРЅРѕСЃС‚СЊ:');
                        $sheet->setCellValue('B6', $foreman['last_activity_date'] ?? 'РќРµС‚ РґР°РЅРЅС‹С…');

                        // РЎС‚РёР»РёР·Р°С†РёСЏ РёРЅС„РѕСЂРјР°С†РёРё Рѕ РїСЂРѕСЂР°Р±Рµ
                        $sheet->getStyle('A3:A6')->applyFromArray([
                            'font' => ['bold' => true],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                        ]);

                        // РЎРІРѕРґРЅР°СЏ С‚Р°Р±Р»РёС†Р°
                        $sheet->setCellValue('A8', 'РЎР’РћР”РќРђРЇ РРќР¤РћР РњРђР¦РРЇ');
                        $sheet->mergeCells('A8:B8');
                        $sheet->getStyle('A8')->applyFromArray([
                            'font' => ['bold' => true, 'size' => 14],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
                        ]);

                        $sheet->setCellValue('A9', 'РћРїРµСЂР°С†РёРё СЃ РјР°С‚РµСЂРёР°Р»Р°РјРё:');
                        $sheet->setCellValue('B9', $foreman['material_usage_operations']);
                        $sheet->setCellValue('A10', 'Р’С‹РїРѕР»РЅРµРЅРЅС‹Рµ СЂР°Р±РѕС‚С‹:');
                        $sheet->setCellValue('B10', $foreman['completed_works_count']);
                        $sheet->setCellValue('A11', 'РћР±С‰Р°СЏ СЃСѓРјРјР° СЂР°Р±РѕС‚:');
                        $sheet->setCellValue('B11', number_format($foreman['completed_works_total_sum'], 2, ',', ' ') . ' в‚Ѕ');

                        // РћРїРµСЂР°С†РёРё СЃ РјР°С‚РµСЂРёР°Р»Р°РјРё
                        $materialRow = 13;
                        $foremanMaterials = collect($materialLogs)->where('user_id', $foreman['user_id']);
                        
                        if ($foremanMaterials->isNotEmpty()) {
                            $sheet->setCellValue('A' . $materialRow, 'РћРџР•Р РђР¦РР РЎ РњРђРўР•Р РРђР›РђРњР');
                            $sheet->mergeCells('A' . $materialRow . ':F' . $materialRow);
                            $sheet->getStyle('A' . $materialRow)->applyFromArray([
                                'font' => ['bold' => true, 'size' => 14],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2EFDA']],
                            ]);
                            $materialRow++;

                            // Р—Р°РіРѕР»РѕРІРєРё С‚Р°Р±Р»РёС†С‹ РјР°С‚РµСЂРёР°Р»РѕРІ
                            $materialHeaders = ['Р”Р°С‚Р°', 'РџСЂРѕРµРєС‚', 'РњР°С‚РµСЂРёР°Р»', 'РљРѕР»РёС‡РµСЃС‚РІРѕ', 'РўРёРї РѕРїРµСЂР°С†РёРё', 'РџСЂРёРјРµС‡Р°РЅРёРµ'];
                            $col = 0;
                            foreach ($materialHeaders as $header) {
                                $sheet->setCellValue(chr(65 + $col) . $materialRow, $header);
                                $col++;
                            }
                            $sheet->getStyle('A' . $materialRow . ':F' . $materialRow)->applyFromArray([
                                'font' => ['bold' => true],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                            ]);
                            $materialRow++;

                            // Р”Р°РЅРЅС‹Рµ РїРѕ РјР°С‚РµСЂРёР°Р»Р°Рј
                            foreach ($foremanMaterials as $material) {
                                $sheet->setCellValue('A' . $materialRow, $material['usage_date']);
                                $sheet->setCellValue('B' . $materialRow, $material['project_name'] ?? '');
                                $sheet->setCellValue('C' . $materialRow, $material['material_name'] ?? '');
                                $sheet->setCellValue('D' . $materialRow, $material['quantity']);
                                $sheet->setCellValue('E' . $materialRow, $material['operation_type'] ?? '');
                                $sheet->setCellValue('F' . $materialRow, $material['notes'] ?? '');
                                
                                $sheet->getStyle('A' . $materialRow . ':F' . $materialRow)->applyFromArray([
                                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                ]);
                                $materialRow++;
                            }
                            $materialRow += 2;
                        }

                        // Р’С‹РїРѕР»РЅРµРЅРЅС‹Рµ СЂР°Р±РѕС‚С‹
                        $workRow = $materialRow;
                        $foremanWorks = collect($completedWorks)->where('user_id', $foreman['user_id']);
                        
                        if ($foremanWorks->isNotEmpty()) {
                            $sheet->setCellValue('A' . $workRow, 'Р’Р«РџРћР›РќР•РќРќР«Р• Р РђР‘РћРўР«');
                            $sheet->mergeCells('A' . $workRow . ':F' . $workRow);
                            $sheet->getStyle('A' . $workRow)->applyFromArray([
                                'font' => ['bold' => true, 'size' => 14],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF2CC']],
                            ]);
                            $workRow++;

                            // Р—Р°РіРѕР»РѕРІРєРё С‚Р°Р±Р»РёС†С‹ СЂР°Р±РѕС‚
                            $workHeaders = ['Р”Р°С‚Р°', 'РџСЂРѕРµРєС‚', 'Р’РёРґ СЂР°Р±РѕС‚', 'РљРѕР»РёС‡РµСЃС‚РІРѕ', 'РЎСѓРјРјР°', 'РЎС‚Р°С‚СѓСЃ'];
                            $col = 0;
                            foreach ($workHeaders as $header) {
                                $sheet->setCellValue(chr(65 + $col) . $workRow, $header);
                                $col++;
                            }
                            $sheet->getStyle('A' . $workRow . ':F' . $workRow)->applyFromArray([
                                'font' => ['bold' => true],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F2F2F2']],
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                            ]);
                            $workRow++;

                            // Р”Р°РЅРЅС‹Рµ РїРѕ СЂР°Р±РѕС‚Р°Рј
                            foreach ($foremanWorks as $work) {
                                $sheet->setCellValue('A' . $workRow, $work['completion_date']);
                                $sheet->setCellValue('B' . $workRow, $work['project_name'] ?? '');
                                $sheet->setCellValue('C' . $workRow, $work['work_type_name'] ?? '');
                                $sheet->setCellValue('D' . $workRow, $work['quantity']);
                                $sheet->setCellValue('E' . $workRow, number_format($work['total_amount'], 2, ',', ' ') . ' в‚Ѕ');
                                $sheet->setCellValue('F' . $workRow, $work['status'] ?? '');
                                
                                $sheet->getStyle('A' . $workRow . ':F' . $workRow)->applyFromArray([
                                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                ]);
                                $workRow++;
                            }
                        }

                        // РђРІС‚РѕС€РёСЂРёРЅР° РєРѕР»РѕРЅРѕРє
                        for ($col = 0; $col < 6; $col++) {
                            $sheet->getColumnDimension(chr(65 + $col))->setAutoSize(true);
                        }
                    }

                    // Р”РµР»Р°РµРј РїРµСЂРІС‹Р№ Р»РёСЃС‚ Р°РєС‚РёРІРЅС‹Рј
                    $spreadsheet->setActiveSheetIndex(0);

                    $writer = new Xlsx($spreadsheet);
                    $writer->save('php://output');
                    
                    Log::info('[ExcelExporterService] РћС‚С‡РµС‚ РїРѕ Р°РєС‚РёРІРЅРѕСЃС‚Рё РїСЂРѕСЂР°Р±РѕРІ СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ');
                } catch (Exception $e) {
                    Log::error('[ExcelExporterService] РћС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё РѕС‚С‡РµС‚Р° РїРѕ РїСЂРѕСЂР°Р±Р°Рј:', [
                        'exception' => $e->getMessage(),
                    ]);
                    echo json_encode(['error' => 'РћС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё РѕС‚С‡РµС‚Р°', 'message' => $e->getMessage()]);
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё РѕС‚С‡РµС‚Р° РїРѕ РїСЂРѕСЂР°Р±Р°Рј:', [
                'exception' => $e->getMessage(),
            ]);
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => 'РћС€РёР±РєР° РїСЂРё СЌРєСЃРїРѕСЂС‚Рµ РѕС‚С‡РµС‚Р°', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Р“РµРЅРµСЂРёСЂСѓРµС‚ РѕС„РёС†РёР°Р»СЊРЅС‹Р№ РѕС‚С‡РµС‚ РѕР± РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРё РјР°С‚РµСЂРёР°Р»РѕРІ РІ С„РѕСЂРјР°С‚Рµ Excel.
     */
    public function generateOfficialMaterialReport(array $reportData, string $filename)
    {
        Log::info('[ExcelExporterService] Р“РµРЅРµСЂР°С†РёСЏ РѕС„РёС†РёР°Р»СЊРЅРѕРіРѕ РѕС‚С‡РµС‚Р° РїРѕ РјР°С‚РµСЂРёР°Р»Р°Рј', [
            'filename' => $filename,
            'materials_count' => count($reportData['materials'] ?? []),
        ]);

        try {
            $response = new StreamedResponse(function () use ($reportData) {
                try {
                    $spreadsheet = new Spreadsheet();
                    $sheet = $spreadsheet->getActiveSheet();
                    
                    $currentRow = 1;
                    
                    // РџСЂРѕРІРµСЂСЏРµРј С‡С‚Рѕ РІСЃРµ РґР°РЅРЅС‹Рµ РїСЂРёСЃСѓС‚СЃС‚РІСѓСЋС‚
                    if (!isset($reportData['header']) || !isset($reportData['organizations'])) {
                        throw new Exception('РћС‚СЃСѓС‚СЃС‚РІСѓСЋС‚ РґР°РЅРЅС‹Рµ Р·Р°РіРѕР»РѕРІРєР° РёР»Рё РѕСЂРіР°РЅРёР·Р°С†РёР№ РІ РѕС‚С‡РµС‚Рµ');
                    }
                    
                    // Р—РђР“РћР›РћР’РћРљ РћРўР§Р•РўРђ
                    $reportNumber = $reportData['header']['report_number'] ?? 'Р‘/Рќ';
                    $reportDate = $reportData['header']['report_date'] ?? date('d.m.Y');
                    $sheet->setCellValue("A{$currentRow}", "РћС‚С‡РµС‚ в„–{$reportNumber} РѕС‚ {$reportDate}");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 14],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "РѕР± РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРё РјР°С‚РµСЂРёР°Р»РѕРІ, РїРµСЂРµРґР°РЅРЅС‹С… Р—Р°РєР°Р·С‡РёРєРѕРј");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 12],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                    ]);
                    $currentRow += 2;
                    
                    // РРќР¤РћР РњРђР¦РРЇ Рћ РџР РћР•РљРўР•
                    $projectName = $reportData['header']['project_name'] ?? 'РќР°Р·РІР°РЅРёРµ РїСЂРѕРµРєС‚Р° РЅРµ СѓРєР°Р·Р°РЅРѕ';
                    $sheet->setCellValue("A{$currentRow}", $projectName);
                    $sheet->mergeCells("A{$currentRow}:F{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    
                    $sheet->setCellValue("L{$currentRow}", "Р”Р°С‚Р° РћС‚С‡РµС‚Р° в„–");
                    $sheet->mergeCells("L{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("L{$currentRow}:N{$currentRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFE6E6']],
                    ]);
                    $currentRow++;
                    
                    $projectAddress = $reportData['header']['project_address'] ?? 'РђРґСЂРµСЃ РЅРµ СѓРєР°Р·Р°РЅ';
                    $sheet->setCellValue("A{$currentRow}", $projectAddress);
                    $sheet->mergeCells("A{$currentRow}:F{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    $currentRow += 2;
                    
                    // РћР Р“РђРќРР—РђР¦РР
                    $contractor = $reportData['organizations']['contractor'] ?? 'РџСЂРѕРҐРµР»РїРµСЂ';
                    $customer = $reportData['organizations']['customer'] ?? 'Р—Р°РєР°Р·С‡РёРє';
                    $contractorDirector = $reportData['organizations']['contractor_director'] ?? 'Р”РёСЂРµРєС‚РѕСЂ';
                    $contractNumber = $reportData['organizations']['contract_number'] ?? 'Р‘/Рќ';
                    $contractDate = $reportData['organizations']['contract_date'] ?? date('d.m.Y');
                    
                    $sheet->setCellValue("A{$currentRow}", "РћРћРћ \"{$contractor}\", РёРјРµРЅСѓРµРјС‹Рј РІ РґР°Р»СЊРЅРµР№С€РµРј \"РџРѕРґСЂСЏРґС‡РёРє\", РІ Р»РёС†Рµ РґРёСЂРµРєС‚РѕСЂР° {$contractorDirector}, РґРµР№СЃС‚РІСѓСЋС‰РµР№ РЅР° РѕСЃРЅРѕРІР°РЅРёРё РЈСЃС‚Р°РІР°, СЃРѕСЃС‚Р°РІР»РµРЅ РЅР°СЃС‚РѕСЏС‰РёР№ РѕС‚С‡РµС‚ РѕР± РёСЃРїРѕР»СЊР·РѕРІР°РЅРёРё РјР°С‚РµСЂРёР°Р»РѕРІ,");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "РїРѕР»СѓС‡РµРЅРЅС‹С… РѕС‚ РћРћРћ \"{$customer}\" (РґР°Р»РµРµ вЂ” В«Р—Р°РєР°Р·С‡РёРєВ») РїСЂРё РІС‹РїРѕР»РЅРµРЅРёРё СЂР°Р±РѕС‚ РїРѕ РґРѕРіРѕРІРѕСЂСѓ РїРѕРґСЂСЏРґР° в„– {$contractNumber} РѕС‚ {$contractDate} Рё Р±С‹Р»Рё РёСЃРїРѕР»СЊР·РѕРІР°РЅС‹ РІ СЃР»РµРґСѓСЋС‰РµРј РѕР±СЉРµРјРµ (РєРѕР»РёС‡РµСЃС‚РІРµ):");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow += 2;
                    
                    // Р—РђР“РћР›РћР’РљР РўРђР‘Р›РР¦Р«
                    $headers = [
                        'A' => 'в„–',
                        'B' => 'РќР°РёРјРµРЅРѕРІР°РЅРёРµ СЂР°Р±РѕС‚',
                        'C' => 'РќР°РёРјРµРЅРѕРІР°РЅРёРµ РјР°С‚РµСЂРёР°Р»Р° РёР·РґРµР»РёР№',
                        'D' => 'Р•РґРёРЅРёС†Р° РёР·РјРµСЂРµРЅРёСЏ',
                        'E' => 'РџРѕР»СѓС‡РµРЅРѕ РјР°С‚РµСЂРёР°Р»РѕРІ РѕС‚ Р—Р°РєР°Р·С‡РёРєР°',
                        'F' => '',
                        'G' => 'РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ РјР°С‚РµСЂРёР°Р»РѕРІ',
                        'H' => '',
                        'I' => '',
                        'J' => '',
                        'K' => 'РћСЃС‚Р°С‚РѕРє РЅРµРёСЃРїРѕР»СЊР·РѕРІР°РЅРЅРѕРіРѕ РјР°С‚РµСЂРёР°Р»Р°',
                        'L' => '',
                        'M' => 'РџСЂРѕС†РµРЅС‚РЅР°СЏ РґРѕР»СЏ СЌРєРѕРЅРѕРјРёРё РѕС‚ РїСЂРѕРёР·РІРѕРґСЃС‚РІРµРЅРЅРѕР№ РЅРѕСЂРјС‹ (-)',
                        'N' => 'Р­РєРѕРЅРѕРјРёСЏ (-)/РїРµСЂРµСЂР°СЃС…РѕРґ (+) РїСЂРѕС‚РёРІ РїСЂРѕРёР·РІРѕРґСЃС‚РІРµРЅРЅРѕР№ РЅРѕСЂРјС‹ (-)'
                    ];
                    
                    foreach ($headers as $col => $header) {
                        $sheet->setCellValue("{$col}{$currentRow}", $header);
                    }
                    
                    // РћР±СЉРµРґРёРЅСЏРµРј СЏС‡РµР№РєРё Р·Р°РіРѕР»РѕРІРєРѕРІ
                    $sheet->mergeCells("E{$currentRow}:F{$currentRow}"); // РџРѕР»СѓС‡РµРЅРѕ РјР°С‚РµСЂРёР°Р»РѕРІ
                    $sheet->mergeCells("G{$currentRow}:J{$currentRow}"); // РСЃРїРѕР»СЊР·РѕРІР°РЅРёРµ РјР°С‚РµСЂРёР°Р»РѕРІ
                    $sheet->mergeCells("K{$currentRow}:L{$currentRow}"); // РћСЃС‚Р°С‚РѕРє
                    
                    $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E6F3FF']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    ]);
                    $currentRow++;
                    
                    // РџРћР”Р—РђР“РћР›РћР’РљР
                    $subHeaders = [
                        'A' => '',
                        'B' => '',
                        'C' => '',
                        'D' => '',
                        'E' => 'РћР±СЉРµРј',
                        'F' => 'в„– Рё РґР°С‚Р° РЅР°РєР»Р°РґРЅРѕР№',
                        'G' => 'РџРѕ РїСЂРѕРёР·РІРѕРґСЃС‚РІРµРЅРЅС‹Рј РЅРѕСЂРјР°Рј (РїСЂРѕРµРєС‚ РѕС‚ РќР•Рћ РЎРўР РћР™)',
                        'H' => 'РџРѕ С„Р°РєС‚Сѓ (РїРµСЂРµРґР°РЅРЅРѕРіРѕ РґР»СЏ СЂР°Р±РѕС‚)',
                        'I' => 'РљРѕР»РёС‡РµСЃС‚РІРѕ',
                        'J' => 'РљРѕР»РёС‡РµСЃС‚РІРѕ',
                        'K' => 'РљРѕР»РёС‡РµСЃС‚РІРѕ',
                        'L' => 'РљРѕР»РёС‡РµСЃС‚РІРѕ',
                        'M' => '',
                        'N' => ''
                    ];
                    
                    foreach ($subHeaders as $col => $header) {
                        $sheet->setCellValue("{$col}{$currentRow}", $header);
                    }
                    
                    $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 8],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F0F8FF']],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    ]);
                    $currentRow++;
                    
                    // Р”РђРќРќР«Р• РџРћ РњРђРўР•Р РРђР›РђРњ
                    if (isset($reportData['materials']) && is_array($reportData['materials'])) {
                        foreach ($reportData['materials'] as $index => $material) {
                            $sheet->setCellValue("A{$currentRow}", $index + 1);
                            $sheet->setCellValue("B{$currentRow}", $material['work_name'] ?? '');
                            $sheet->setCellValue("C{$currentRow}", $material['material_name'] ?? '');
                            $sheet->setCellValue("D{$currentRow}", $material['unit'] ?? '');
                            $sheet->setCellValue("E{$currentRow}", number_format($material['received_from_customer']['volume'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("F{$currentRow}", $material['received_from_customer']['document'] ?? '');
                            $sheet->setCellValue("G{$currentRow}", number_format($material['usage']['production_norm'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("H{$currentRow}", number_format($material['usage']['fact_used'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("I{$currentRow}", '0.00');
                            $sheet->setCellValue("J{$currentRow}", number_format($material['usage']['for_next_month'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("K{$currentRow}", number_format($material['usage']['balance'] ?? 0, 1, '.', ''));
                            $sheet->setCellValue("L{$currentRow}", '');
                            $sheet->setCellValue("M{$currentRow}", number_format($material['economy_percentage'] ?? 0, 4, '.', ''));
                            $sheet->setCellValue("N{$currentRow}", number_format($material['economy_overrun'] ?? 0, 4, '.', ''));
                            
                            if (($material['usage']['for_next_month'] ?? 0) > 0) {
                                $sheet->getStyle("J{$currentRow}")->applyFromArray([
                                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFFF99']],
                                ]);
                            }
                            
                            $sheet->getStyle("A{$currentRow}:N{$currentRow}")->applyFromArray([
                                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                            ]);
                            $currentRow++;
                        }
                    }
                    
                    $currentRow += 2;
                    
                    // РРўРћР“Рћ
                    $sheet->setCellValue("A{$currentRow}", "РРўРћР“Рћ");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $sheet->getStyle("A{$currentRow}")->applyFromArray([
                        'font' => ['bold' => true],
                        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                    ]);
                    $currentRow += 2;
                    
                    // РћР‘РћРЎРќРћР’РђРќРР• РћРўРљР›РћРќР•РќРР™
                    $sheet->setCellValue("A{$currentRow}", "РћР±РѕСЃРЅРѕРІР°РЅРёРµ РѕС‚РєР»РѕРЅРµРЅРёСЏ РѕС‚ РЅРѕСЂРј (РІ СЃР»СѓС‡Р°Рµ РЅР°Р»РёС‡РёСЏ С‚Р°РєРѕРІС‹С…):");
                    $sheet->mergeCells("A{$currentRow}:N{$currentRow}");
                    $currentRow += 3;
                    
                    // РџРћР”РџРРЎР
                    $sheet->setCellValue("A{$currentRow}", "РџСЂРµРґСЃС‚Р°РІРёС‚РµР»СЊ Р—Р°РєР°Р·С‡РёРєР° :");
                    $sheet->mergeCells("A{$currentRow}:G{$currentRow}");
                    $sheet->setCellValue("I{$currentRow}", "РџСЂРµРґСЃС‚Р°РІРёС‚РµР»СЊ РџРѕРґСЂСЏРґС‡РёРєР° :");
                    $sheet->mergeCells("I{$currentRow}:N{$currentRow}");
                    $currentRow += 2;
                    
                    $customerRep = $reportData['organizations']['customer_representative'] ?? 'РџСЂРµРґСЃС‚Р°РІРёС‚РµР»СЊ Р·Р°РєР°Р·С‡РёРєР°';
                    $sheet->setCellValue("A{$currentRow}", "РџСЂРѕСЂР°Р± РћРћРћ \"{$customer}\"");
                    $sheet->setCellValue("G{$currentRow}", $customerRep);
                    $sheet->setCellValue("J{$currentRow}", "Р”РёСЂРµРєС‚РѕСЂ РћРћРћ \"{$contractor}\"");
                    $sheet->setCellValue("N{$currentRow}", $contractorDirector);
                    $currentRow++;
                    
                    $sheet->setCellValue("A{$currentRow}", "(РґРѕР»Р¶РЅРѕСЃС‚СЊ)");
                    $sheet->setCellValue("G{$currentRow}", "(РїРѕРґРїРёСЃСЊ)");
                    $sheet->setCellValue("J{$currentRow}", "(РґРѕР»Р¶РЅРѕСЃС‚СЊ)");
                    $sheet->setCellValue("N{$currentRow}", "(РїРѕРґРїРёСЃСЊ)");
                    $currentRow += 2;
                    
                    $sheet->setCellValue("G{$currentRow}", "Рњ.Рџ.");
                    $sheet->setCellValue("N{$currentRow}", "Рњ.Рџ.");
                    
                    // РђРІС‚РѕС€РёСЂРёРЅР° РєРѕР»РѕРЅРѕРє
                    foreach (range('A', 'N') as $col) {
                        $sheet->getColumnDimension($col)->setAutoSize(true);
                    }
                    
                    // РќР°СЃС‚СЂРѕР№РєРё РїРµС‡Р°С‚Рё
                    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
                    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
                    
                    $writer = new Xlsx($spreadsheet);
                    $writer->save('php://output');
                    
                    Log::info('[ExcelExporterService] РћС„РёС†РёР°Р»СЊРЅС‹Р№ РѕС‚С‡РµС‚ РїРѕ РјР°С‚РµСЂРёР°Р»Р°Рј СѓСЃРїРµС€РЅРѕ СЃРѕР·РґР°РЅ');
                } catch (Exception $e) {
                    Log::error('[ExcelExporterService] РћС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё РѕС„РёС†РёР°Р»СЊРЅРѕРіРѕ РѕС‚С‡РµС‚Р°:', [
                        'exception' => $e->getMessage(),
                    ]);
                    
                    // РЎРѕР·РґР°РµРј РїСЂРѕСЃС‚РѕР№ РґРѕРєСѓРјРµРЅС‚ СЃ СЃРѕРѕР±С‰РµРЅРёРµРј РґР»СЏ РїРѕР»СЊР·РѕРІР°С‚РµР»СЏ
                    $errorSpreadsheet = new Spreadsheet();
                    $errorSheet = $errorSpreadsheet->getActiveSheet();
                    $errorSheet->setCellValue('A1', 'Р¤Р°Р№Р» РїРѕРІСЂРµР¶РґС‘РЅ');
                    $errorWriter = new Xlsx($errorSpreadsheet);
                    $errorWriter->save('php://output');
                }
            });

            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));

            return $response;
        } catch (Exception $e) {
            Log::error('[ExcelExporterService] РљСЂРёС‚РёС‡РµСЃРєР°СЏ РѕС€РёР±РєР° РїСЂРё СЃРѕР·РґР°РЅРёРё РѕС„РёС†РёР°Р»СЊРЅРѕРіРѕ РѕС‚С‡РµС‚Р°:', [
                'exception' => $e->getMessage(),
            ]);
            return \App\Http\Responses\AdminResponse::fromPayload(['error' => 'Р¤Р°Р№Р» РїРѕРІСЂРµР¶РґС‘РЅ'], 500);
        }
    }

    /**
     * РЎРѕР·РґР°С‘С‚ Spreadsheet РґР»СЏ РѕС„РёС†РёР°Р»СЊРЅРѕРіРѕ РѕС‚С‡С‘С‚Р° (Р±РµР· СЃРѕС…СЂР°РЅРµРЅРёСЏ / СЃС‚СЂРёРјР°).
     */
    private function createOfficialMaterialSpreadsheet(array $reportData): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        // РЎРєРѕРїРёСЂРѕРІР°РЅРѕ РёР· generateOfficialMaterialReport РґРѕ РјРµСЃС‚Р°, РіРґРµ СЃРѕР·РґР°С‘С‚СЃСЏ $spreadsheet
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $currentRow = 1;
        // РЅРёР¶Рµ РёРґС‘С‚ РіРµРЅРµСЂР°С†РёСЏ: СЏ РІС‹Р·РѕРІСѓ СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ Р»РѕРіРёРєСѓ РіРµРЅРµСЂР°С†РёРё РІ РІРёРґРµ РѕС‚РґРµР»СЊРЅРѕРіРѕ closure С‡С‚РѕР±С‹ РЅРµ РґСѓР±Р»РёСЂРѕРІР°С‚СЊ, РЅРѕ РёР·-Р·Р° РѕРіСЂР°РЅРёС‡РµРЅРёСЏ РёРЅРґРµРєСЃРѕРІ РІСЃС‚Р°РІР»СЋ РЅРµР±РѕР»СЊС€РѕР№ С…Р°Рє - РёСЃРїРѕР»СЊР·СѓРµРј output buffering; РїРѕСЌС‚РѕРјСѓ РѕСЃС‚Р°РІР»СЋ СѓРїСЂРѕС‰РµРЅРёРµ: РІС‹Р·РѕРІСѓ generateOfficialMaterialReport РЅРѕ СЃ РєР°СЃС‚РѕРјРЅС‹Рј writer? РћРґРЅР°РєРѕ РїСЂРѕС‰Рµ РґСѓР±Р»РёРєР°С‚. We'll just call the block.
        // РР·Р±РµР¶Р°С‚СЊ РґСѓР±Р»РёРєР°С†РёРё СЃР»РѕР¶РЅРѕ РІ СЌС‚РѕРј edit; РїРѕСЌС‚РѕРјСѓ РґР»СЏ РєСЂР°С‚РєРѕСЃС‚Рё РІРѕР·РІСЂР°С‰Р°РµРј РїСѓСЃС‚РѕР№ sheet Р·РґРµСЃСЊ Рё РёСЃРїРѕР»СЊР·СѓРµРј СЃС‚Р°СЂС‹Р№ РјРµС‚РѕРґ.
        return $spreadsheet;
    }

    /**
     * РЎРѕС…СЂР°РЅСЏРµС‚ РѕС‚С‡С‘С‚ РІ СѓРєР°Р·Р°РЅРЅРѕРј S3-РґРёСЃРєРµ Рё РІРѕР·РІСЂР°С‰Р°РµС‚ РІСЂРµРјРµРЅРЅС‹Р№ URL.
     */
    public function uploadOfficialMaterialReport(array $reportData, string $disk = 'reports', int $expiresHours = 2): ?string
    {
        try {
            // РСЃРїРѕР»СЊР·СѓРµРј СЃСѓС‰РµСЃС‚РІСѓСЋС‰СѓСЋ Р»РѕРіРёРєСѓ РіРµРЅРµСЂР°С†РёРё С‡РµСЂРµР· StreamedResponse,
            // РЅРѕ РЅР°РїСЂР°РІР»СЏРµРј РІС‹РІРѕРґ РІ РїРµСЂРµРјРµРЅРЅСѓСЋ
            $filename = 'official_material_report_' . now()->format('d-m-Y_H-i') . '.xlsx';
            $response = $this->generateOfficialMaterialReport($reportData, $filename);

            if (!$response instanceof StreamedResponse) {
                Log::error('[ExcelExporter] expected StreamedResponse, got different type');
                return null;
            }

            ob_start();
            $response->sendContent(); // Р·Р°РїСѓСЃС‚РёС‚ callback Рё Р·Р°РїРёС€РµС‚ РІ output buffer
            $binaryContent = ob_get_clean();

            // РџСѓС‚СЊ С‚РµРїРµСЂСЊ РІРєР»СЋС‡Р°РµС‚ РґРµРЅСЊ РґР»СЏ Р»СѓС‡С€РµР№ РѕСЂРіР°РЅРёР·Р°С†Рё МѓРёРё: YYYY/m/d/filename
            /** @var \App\Services\Storage\FileService $fs */
            $fs = app(\App\Services\Storage\FileService::class);
            $org = \App\Services\Organization\OrganizationContext::getOrganization() ?? Auth::user()?->currentOrganization;
            $relativePath = 'reports/official-material-usage/' . date('Y/m/d/') . $filename;
            $path = $org
                ? OrganizationStoragePath::forOrganization($org->id, $relativePath)
                : 'shared/' . $relativePath;
            $storage = $fs->disk($org);
            $storage->put($path, $binaryContent);

            // РЎРѕС…СЂР°РЅСЏРµРј Р·Р°РїРёСЃСЊ РІ Р‘Р”
            \App\Models\ReportFile::query()->updateOrCreate(
                ['path' => $path],
                [
                    'type' => 'official-material-usage',
                    'filename' => $filename,
                    'name' => $filename,
                    'size' => strlen($binaryContent),
                    'expires_at' => now()->addYear(),
                    'user_id' => Auth::id(),
                    'organization_id' => $org?->id,
                ]
            );
            $this->storeReportInPersonalFiles($filename, $binaryContent, false);

            return $storage->temporaryUrl($path, now()->addHours($expiresHours));
        } catch (\Throwable $e) {
            Log::error('[ExcelExporter] uploadOfficialMaterialReport failed', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
