<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\Timekeeping\Services;

use Dompdf\Dompdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class TimekeepingDocumentRenderer
{
    public function render(array $period, string $format, string $organizationName): array
    {
        $snapshot = $period['snapshot'];
        $days = range(1, (int) (new \DateTimeImmutable($snapshot['month'].'-01'))->format('t'));
        $status = ['draft' => 'Черновик', 'submitted' => 'На проверке', 'verified' => 'Проверен', 'approved' => 'Утверждён', 'closed' => 'Закрыт'][$period['status']] ?? 'Требует проверки';
        $table = [['МОСТ. Табель учёта рабочего времени', $organizationName], ['Дата составления документа', date('Y-m-d')], ['Месяц', $snapshot['month']], ['Номер / версия', $period['id'].' / '.$period['revision']], ['Статус', $status], ['Форма / основание', $period['rule_snapshot']['form']['version'], $period['rule_snapshot']['form']['approved_basis_reference'] ?? ''], ['SHA256 снимка', $period['snapshot_sha256']], ['Единицы', 'Минуты. Фактически отработанное время; план приведён отдельно. Пустая ячейка факта: длительность не подтверждена.']];
        $table[] = array_merge(['Табельный номер', 'ФИО / договор', 'Показатель'], array_map('strval', $days), ['Итого']);
        foreach ($snapshot['rows'] as $row) {
            if (($row['relation_type'] ?? null) === 'civil') {
                continue;
            }
            foreach (['norm_minutes' => 'Норма', 'planned_minutes' => 'План', 'fact_minutes' => 'Факт', 'night_minutes' => 'Ночь', 'weekend_minutes' => 'Выходные / праздники', 'overtime_minutes' => 'Сверхурочно', 'absence_code' => 'Неявка'] as $key => $label) {
                $table[] = array_merge([$row['personnel_number'], $row['full_name'].' / '.($row['contract_number'] ?? 'Сверка'), $label], array_map(static fn (array $day): mixed => $key === 'absence_code' ? self::absenceCode($day[$key] ?? null) : ($day[$key] ?? ''), $row['days']), [$row['summary'][$key] ?? '']);
            }
        }
        $matrixEnd = count($table);
        $table[] = ['Коды собственной формы', 'ОТП: отпуск (вид определяется основанием); Б: больничный; К: командировка; НУ: тип неявки требует сверки. Коды не создают отработанные часы.'];
        $table[] = ['Составил: ФИО, должность, подпись, дата', '________________________________'];
        $table[] = ['Проверил: ФИО, должность, подпись, дата', '________________________________'];
        $table[] = ['Утвердил: ФИО, должность, подпись, дата', '________________________________'];
        $table[] = ['Способ подписания', 'Бумажная подпись. Скан регистрируется оператором. Электронная подпись не создаётся.'];
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            foreach ($table as $record) {
                fputcsv($stream, array_map(static fn ($v) => preg_match('/^[=+@\-\t\r]/u', (string) $v) ? "'".(string) $v : $v, $record), ';', '"', '');
            }
            rewind($stream);
            $body = "\xEF\xBB\xBF".stream_get_contents($stream);
            fclose($stream);

            return ['body' => $body, 'mime' => 'text/csv; charset=utf-8'];
        }
        if ($format === 'xlsx') {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Табель');
            foreach ($table as $i => $record) {
                if ($i < 8 || $i >= $matrixEnd) {
                    $sheet->setCellValueExplicit([1, $i + 1], (string) $record[0], DataType::TYPE_STRING);
                    $sheet->setCellValueExplicit([4, $i + 1], implode(' / ', array_slice($record, 1)), DataType::TYPE_STRING);
                    $sheet->mergeCells([1, $i + 1, 3, $i + 1]);
                    $sheet->mergeCells([4, $i + 1, count($days) + 4, $i + 1]);

                    continue;
                }
                foreach ($record as $j => $value) {
                    $sheet->setCellValueExplicit([$j + 1, $i + 1], (string) $value, DataType::TYPE_STRING);
                }
            }
            $sheet->getColumnDimension('A')->setWidth(20);
            $sheet->getColumnDimension('B')->setWidth(34);
            $sheet->getColumnDimension('C')->setWidth(28);
            for ($column = 4; $column <= count($days) + 3; $column++) {
                $sheet->getColumnDimensionByColumn($column)->setWidth(4.5);
            }
            $sheet->getColumnDimensionByColumn(count($days) + 4)->setWidth(9);
            $sheet->getPageSetup()->setOrientation('landscape')->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A3)->setFitToWidth(1)->setFitToHeight(0);
            $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(9, 9);
            $range = [1, 1, count($days) + 4, count($table)];
            $sheet->getStyle($range)->getFont()->setSize(9);
            $sheet->getStyle($range)->getAlignment()->setWrapText(true);
            $sheet->getStyle([1, 9, count($days) + 4, 9])->getFont()->setBold(true);
            $sheet->getStyle('A1:A8')->getFont()->setBold(true);
            $stream = fopen('php://temp', 'w+');
            (new Xlsx($spreadsheet))->save($stream);
            rewind($stream);
            $body = stream_get_contents($stream);
            fclose($stream);
            $spreadsheet->disconnectWorksheets();

            return ['body' => $body, 'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        }
        if ($format !== 'pdf') {
            throw new \InvalidArgumentException('format');
        }
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<html lang="ru"><head><meta charset="utf-8"><style>@page{margin:24pt}body{font-family:DejaVu Sans;font-size:9px;color:#18212b}table{border-collapse:collapse;width:100%}td,th{border:1px solid #98a3ae;padding:4px;overflow-wrap:break-word}tr{page-break-inside:avoid}.meta td:first-child{width:18%;font-weight:bold}.matrix{table-layout:fixed;margin:12px 0}.matrix td,.matrix th{padding:3px 1px;text-align:center}.matrix td:nth-child(2){text-align:left}.matrix th{background:#e9eef2;font-weight:bold}.signatures td:first-child{width:30%;font-weight:bold}.signatures tr{height:24px}thead{display:table-header-group}</style></head><body><table class="meta">';
        foreach (array_slice($table, 0, 8) as $record) {
            $html .= '<tr><td>'.$escape($record[0]).'</td><td>'.$escape(implode(' / ', array_slice($record, 1))).'</td></tr>';
        }
        $dayWidth = 61 / count($days);
        $html .= '</table><table class="matrix"><colgroup><col style="width:6%"><col style="width:18%"><col style="width:12%">'.str_repeat('<col style="width:'.$dayWidth.'%">', count($days)).'<col style="width:3%"></colgroup>';
        foreach (array_slice($table, 8, $matrixEnd - 8) as $index => $record) {
            $tag = $index === 0 ? 'th' : 'td';
            $html .= $index === 0 ? '<thead><tr>' : '<tr>';
            foreach ($record as $column => $value) {
                $width = $column === 0 ? 6 : ($column === 1 ? 18 : ($column === 2 ? 12 : ($column === count($record) - 1 ? 3 : $dayWidth)));
                $style = $index === 0 ? ' style="width:'.$width.'%"' : '';
                $html .= '<'.$tag.$style.'>'.$escape($value).'</'.$tag.'>';
            }
            $html .= $index === 0 ? '</tr></thead><tbody>' : '</tr>';
        }
        $html .= '</tbody></table><table class="signatures">';
        foreach (array_slice($table, $matrixEnd) as $record) {
            $html .= '<tr><td>'.$escape($record[0]).'</td><td>'.$escape($record[1]).'</td></tr>';
        }
        $html .= '</table></body></html>';
        $pdf = new Dompdf(['isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('a3', 'landscape');
        $pdf->render();

        return ['body' => $pdf->output(), 'mime' => 'application/pdf'];
    }

    private static function absenceCode(?string $code): string
    {
        return match ($code) {
            null, '' => '',
            'vacation' => 'ОТП',
            'sick_leave' => 'Б',
            'business_trip' => 'К',
            default => 'НУ',
        };
    }
}
