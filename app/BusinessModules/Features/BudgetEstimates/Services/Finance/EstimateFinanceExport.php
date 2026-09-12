<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Finance;

use App\Models\User;
use App\Services\Storage\FileService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

final class EstimateFinanceExport
{
    public function __construct(private readonly EstimateFinanceService $finance, private readonly FileService $files) {}

    public function download(User $actor, int $projectId, ?int $estimateId, string $basis, string $view = 'plan'): Response
    {
        if (! in_array($view, ['plan', 'execution'], true) || ($estimateId === null && $view !== 'plan')) {
            throw ValidationException::withMessages(['view' => trans_message('estimate_finance.invalid')]);
        }
        $project = $estimateId === null ? $this->finance->projectReport($actor, $projectId, $basis, true) : null;
        $reports = $project !== null ? $project['estimates'] : [$this->finance->report($actor, $projectId, $estimateId, $basis, $view)];
        $book = $this->workbook($reports, $basis, $project['totals'] ?? [], $view);
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

    public function workbook(array $reports, string $basis, array $projectTotals = [], string $view = 'plan'): Spreadsheet
    {
        if ($view === 'execution') {
            return $this->executionWorkbook($reports, $basis);
        }
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

    private function executionWorkbook(array $reports, string $basis): Spreadsheet
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $moneyColumns = ['currency', 'accepted_revenue', 'accepted_cost', $basis === 'with_vat' ? 'difference' : 'execution_difference', 'known_revenue', 'known_cost', 'unknown_revenue', 'unknown_cost', 'unknown_direction'];
        $this->header($summary, 'execution_summary', ['estimate', ...$moneyColumns, 'basis', 'status']);
        $positions = $book->createSheet();
        $this->header($positions, 'detail', ['estimate', 'name', ...$moneyColumns]);
        $sections = $book->createSheet();
        $this->header($sections, 'sections', ['estimate', 'name', ...$moneyColumns]);
        $quantities = $book->createSheet();
        $this->header($quantities, 'execution_volumes', ['estimate', 'name', 'number', 'unit', 'currency', 'planned_volume', 'accepted_quantity', 'remaining_quantity', 'overrun_quantity']);
        $documents = $book->createSheet();
        $this->header($documents, 'execution_documents', ['estimate', 'act_id', 'act_number', 'act_date', 'number', 'direction', 'currency', 'net', 'gross', 'estimate_gross', 'unallocated_gross', 'status']);
        $sources = $book->createSheet();
        $this->header($sources, 'execution_sources', ['estimate', 'name', 'act_id', 'source', 'source_id', 'number', 'direction', 'volume', 'currency', 'net', 'gross', 'allocation_key', 'condition_version']);
        foreach ($reports as $report) {
            $execution = $report['execution'] ?? ['available' => false];
            if (! $execution['available']) {
                $this->row($summary, [$report['name'], null, null, null, null, null, null, null, null, null,
                    trans_message('estimate_finance.'.$basis), trans_message('estimate_finance.execution_unavailable')], []);
                continue;
            }
            $rows = array_column($report['rows'], null, 'key');
            $contracts = array_column($report['contracts'], null, 'id');
            $sectionNames = array_column($report['sections'], 'name', 'id');
            foreach ($execution['summary']['totals'] as $currency => $total) {
                $this->row($summary, [$report['name'], $currency, ...$this->executionValues($total),
                    trans_message('estimate_finance.'.$basis), trans_message('estimate_finance.execution_confirmed')], [3, 4, 5, 6, 7, 8, 9, 10]);
            }
            if ($execution['summary']['totals'] === []) {
                $this->row($summary, [$report['name'], null, null, null, null, null, null, null, null, null,
                    trans_message('estimate_finance.'.$basis), trans_message('estimate_finance.execution_empty')], []);
            }
            foreach ($execution['summary']['positions'] as $position) {
                foreach ($position['currencies'] as $currency => $total) {
                    $this->row($positions, [$report['name'], $rows[$position['target_key']]['name'] ?? $position['target_key'], $currency,
                        ...$this->executionValues($total)], [4, 5, 6, 7, 8, 9, 10, 11]);
                }
            }
            foreach ($execution['summary']['sections'] as $sectionId => $totals) {
                foreach ($totals as $currency => $total) {
                    $this->row($sections, [$report['name'], $sectionNames[$sectionId] ?? $sectionId, $currency,
                        ...$this->executionValues($total)], [4, 5, 6, 7, 8, 9, 10, 11]);
                }
            }
            foreach ($execution['summary']['contract_quantities'] as $entry) {
                $row = $rows[$entry['target_key']] ?? [];
                $this->row($quantities, [$report['name'], $row['name'] ?? $entry['target_key'], $contracts[$entry['contract_id']]['number'] ?? $entry['contract_id'],
                    $row['unit'] ?? null, $entry['currency'], $entry['planned_quantity'], $entry['accepted_quantity'], $entry['remaining_quantity'], $entry['overrun_quantity']], [6, 7, 8, 9]);
            }
            foreach ($execution['documents'] as $document) {
                $this->row($documents, [$report['name'], $document['id'], $document['number'], $document['date'], $contracts[$document['contract_id']]['number'] ?? $document['contract_id'],
                    trans_message('estimate_finance.direction_'.$document['side']), $document['currency'], $document['amount_without_vat'], $document['amount_with_vat'],
                    $document['estimate_amount_with_vat'], $document['unallocated_amount_with_vat'], trans_message('estimate_finance.'.($document['needs_review'] ? 'incomplete' : 'execution_'.$document['status']))], [2, 8, 9, 10, 11]);
            }
            foreach ($execution['rows'] as $source) {
                $this->row($sources, [$report['name'], $source['title'], $source['act_id'], trans_message('estimate_finance.'.$source['source_type']), $source['source_id'],
                    $contracts[$source['contract_id']]['number'] ?? $source['contract_id'], trans_message('estimate_finance.direction_'.$source['side']), $source['quantity'], $source['currency'],
                    $source['amount_without_vat'], $source['amount_with_vat'], $source['allocation_key'], $source['condition_version']], [3, 5, 8, 10, 11, 13]);
            }
        }
        foreach ($book->getAllSheets() as $sheet) {
            $sheet->setAutoFilter('A1:'.$sheet->getHighestColumn().$sheet->getHighestRow());
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function executionValues(array $total): array
    {
        return [$total['revenue'], $total['cost'], $total['difference'], $total['known_revenue'], $total['known_cost'],
            $total['revenue_unpriced_count'], $total['cost_unpriced_count'], $total['unknown_direction_count']];
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
