<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

final class EstimateFinanceExport
{
    public function __construct(private readonly EstimateFinanceService $finance, private readonly FileService $files) {}

    public function download(User $actor, int $projectId, ?int $estimateId, string $basis): Response
    {
        $project = $estimateId === null ? $this->finance->projectReport($actor, $projectId, $basis, true) : null;
        $reports = $project !== null ? $project['estimates'] : [$this->finance->report($actor, $projectId, $estimateId, $basis)];
        $book = $this->workbook($reports, $basis, $project['totals'] ?? []);
        ob_start();
        try {
            (new Xlsx($book))->save('php://output');
            $content = (string) ob_get_contents();
        } finally {
            ob_end_clean();
            $book->disconnectWorksheets();
        }
        $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $key = 'org-'.$actor->current_organization_id.'/estimate-finance/'.Str::uuid().'.xlsx';
        $this->files->putPrivate($key, $content, $mime, hash('sha256', $content));

        return new Response($content, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="estimate-finance.xlsx"']);
    }

    public function workbook(array $reports, string $basis, array $projectTotals = []): Spreadsheet
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $margin = $basis === 'with_vat' ? 'difference' : 'margin';
        $this->header($summary, 'summary', ['name', 'currency', 'limit', 'revenue', 'contract_cost', 'own_cost', $margin, 'unpriced', 'basis', 'revision']);
        $detail = $book->createSheet();
        $this->header($detail, 'detail', ['estimate', 'name', 'unit', 'volume', 'currency', 'limit', 'revenue', 'cost', $margin, 'percent', 'status', 'included_volume', 'basis']);
        $links = $book->createSheet();
        $this->header($links, 'contracts', ['estimate', 'name', 'source', 'number', 'date', 'party', 'volume', 'currency', 'net', 'gross', 'legacy', 'vat', 'status']);
        $sections = $book->createSheet();
        $this->header($sections, 'sections', ['estimate', 'name', 'currency', 'revenue', 'cost', $margin, 'unpriced']);
        foreach ($reports as $report) {
            foreach ($report['totals'] as $index => $total) {
                $this->row($summary, [$report['name'], $total['currency'], $index === 0 ? $report['limit'] : null, $total['revenue'], $total['contract_cost'], $total['own_cost'],
                    $total['complete_margin'], $total['incomplete_count'], trans_message('estimate_finance.'.$basis), $report['revision']], [3, 4, 5, 6, 7, 8, 10]);
            }
            $contracts = array_column($report['contracts'], null, 'id');
            foreach ($report['rows'] as $entry) {
                foreach ($entry['by_currency'] ?: [$entry['currency'] => ['revenue' => '0.00', 'cost' => '0.00']] as $currency => $money) {
                    $this->row($detail, [$report['name'], $entry['name'], $entry['unit'], $entry['quantity'], $currency,
                        $basis === 'with_vat' ? $entry['estimate_amount_with_vat'] : $entry['estimate_amount'],
                        $entry['parent_key'] ? null : $money['revenue'], $money['cost'], $entry['parent_key'] ? null : $entry['margin'], $entry['margin_percent'],
                        $entry['excluded'] ? trans_message('estimate_finance.excluded') : ($entry['complete'] ? trans_message('estimate_finance.complete')
                            : implode('; ', array_map(static fn (string $warning): string => trans_message('estimate_finance.warnings.'.$warning), $entry['warnings']))),
                        $entry['parent_key'] ? $entry['included_quantity'] : null, trans_message('estimate_finance.'.$basis)], [4, 6, 7, 8, 9, 10, 12]);
                }
                foreach ($entry['allocations'] as $allocation) {
                    $contract = $contracts[$allocation['contract_id']] ?? [];
                    $this->row($links, [$report['name'], $entry['name'], trans_message('estimate_finance.'.$allocation['source']),
                        $contract['number'] ?? '', $contract['date'] ?? '', $contract['name'] ?? '', $allocation['quantity'], $allocation['currency'],
                        $allocation['amount_without_vat'], $allocation['amount_with_vat'], $allocation['legacy_amount'] ?? null, $allocation['vat_rate'],
                        trans_message('estimate_finance.'.($allocation['composition_confirmed'] && $allocation['amount_without_vat'] !== null
                            && $allocation['amount_with_vat'] !== null && $allocation['price_basis'] !== 'unknown' && $allocation['side'] !== 'unknown' ? 'complete' : 'incomplete'))], [7, 9, 10, 11, 12]);
                }
            }
            foreach ($report['sections'] as $section) {
                foreach ($section['totals'] as $total) {
                    $this->row($sections, [$report['name'], $section['name'], $total['currency'], $total['revenue'], $total['cost'], $total['complete_margin'], $total['incomplete_count']], [4, 5, 6, 7]);
                }
            }
        }
        foreach ($projectTotals as $total) {
            $this->row($summary, [trans_message('estimate_finance.project_total'), $total['currency'], null, $total['revenue'], $total['contract_cost'],
                $total['own_cost'], $total['complete_margin'], $total['incomplete_count'], trans_message('estimate_finance.'.$basis), null], [4, 5, 6, 7, 8]);
        }
        foreach ($book->getAllSheets() as $sheet) {
            $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().$sheet->getHighestRow());
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function header(Worksheet $sheet, string $title, array $keys): void
    {
        $sheet->setTitle(trans_message('estimate_finance.'.$title));
        $sheet->fromArray(array_map(static fn (string $key): string => trans_message('estimate_finance.'.$key), $keys));
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->freezePane('C2');
        foreach (range(1, count($keys)) as $column) {
            $sheet->getColumnDimensionByColumn($column)->setWidth($column <= 2 ? 42 : 22);
        }
    }

    private function row(Worksheet $sheet, array $values, array $numeric): void
    {
        $row = $sheet->getHighestRow() + 1;
        foreach ($values as $index => $value) {
            $column = $index + 1;
            $number = in_array($column, $numeric, true) && $value !== null && is_numeric($value)
                && strlen(str_replace(['.', '-'], '', (string) $value)) <= 15;
            $sheet->setCellValueExplicit([$column, $row], $value ?? '', $number ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
}
