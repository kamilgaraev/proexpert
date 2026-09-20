<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Smalot\PdfParser\Parser;
use PHPUnit\Framework\Attributes\WithoutErrorHandler;
use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\TestCase;

final class OfficialFormsPdfRenderTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        restore_exception_handler();
        parent::tearDown();
    }

    #[WithoutErrorHandler]
    public function test_ks2_pdf_renders_fixture_totals_and_parties(): void
    {
        $data = $this->baseData();
        $contract = new \App\Models\Contract;
        $contract->setRawAttributes(['id' => 1, 'number' => 'Д-01', 'date' => '2026-01-01', 'is_fixed_amount' => true, 'total_amount' => 1000]);
        $data['project']->organization = $data['customer_org'];
        $contract->setRelation('project', $data['project']);
        $contract->setRelation('organization', $data['customer_org']);
        $contract->setRelation('contractor', $data['contractor']);
        $contract->setRelation('firstParty', null);
        $contract->setRelation('secondParty', null);
        $estimate = new \App\Models\Estimate;
        $estimate->setRawAttributes(['vat_rate' => 22, 'total_amount' => 100, 'total_amount_with_vat' => 122]);
        $contract->setRelation('estimate', $estimate);
        $line = new \App\Models\PerformanceActLine;
        $line->setRawAttributes(['title' => 'Бетонирование фундамента', 'unit' => 'м3', 'quantity' => 2, 'unit_price' => 60, 'amount' => 120,
            'basis_snapshot' => json_encode(['estimate_item' => ['position_number' => '02.01', 'code' => 'БТ-01']]),
        ]);
        $line->setRelation('estimateItem', null);
        $line->setRelation('completedWork', null);
        $act = new class extends \App\Models\ContractPerformanceAct {
            public function loadMissing($relations) { return $this; }
        };
        $act->setRawAttributes(['id' => 1, 'act_document_number' => 'КС-01', 'act_date' => '2026-01-31', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'amount' => 120, 'amount_without_vat' => 100, 'vat_amount' => 20, 'vat_rate' => 20]);
        $act->setRelation('contract', $contract);
        $act->setRelation('lines', new \Illuminate\Database\Eloquent\Collection([$line]));
        $act->setRelation('completedWorks', new \Illuminate\Database\Eloquent\Collection);
        $reflection = new \ReflectionClass(\App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $data = $reflection->getMethod('prepareKS2Data')->invoke($service, $act, $contract);
        $sheet = (new \PhpOffice\PhpSpreadsheet\Spreadsheet)->getActiveSheet();
        $reflection->getMethod('setKS2Items')->invoke($service, $sheet, $act);
        self::assertEquals(20, $sheet->getCell('G4')->getValue());
        self::assertEquals(120, $sheet->getCell('G5')->getValue());
        [$text, $pages] = $this->render('estimates.exports.ks2', $data, 'ks2');

        self::assertSame(1, $pages);
        self::assertStringContainsString('Бетонирование', $text);
        self::assertStringContainsString('Заказчик МОСТ', $text);
        self::assertStringContainsString('Подрядчик МОСТ', $text);
        self::assertStringContainsString('02.01', $text);
        self::assertStringContainsString('120,00', $text);
        self::assertStringContainsString('В том числе НДС', $text);
        self::assertMatchesRegularExpression('/В том числе НДС\s+20,00/u', $text);
        self::assertStringContainsString('Сдал', $text);
        self::assertStringContainsString('Принял', $text);
    }

    #[WithoutErrorHandler]
    public function test_ks3_pdf_renders_distinct_cumulative_columns_for_two_lines(): void
    {
        $data = $this->baseData();
        $data += [
            'works' => collect([
                ['title' => 'Первая строка', 'code' => 'A', 'amount' => 60, 'from_start' => 100, 'year_total' => 60],
                ['title' => 'Вторая строка', 'code' => 'B', 'amount' => 40, 'from_start' => 50, 'year_total' => 40],
            ]),
            'total_amount' => 100,
            'vat_amount' => 20,
            'year_total' => 100,
            'total_from_start' => 150,
            'remaining_amount' => 850,
            'period_start' => Carbon::parse('2026-01-01'),
            'period_end' => Carbon::parse('2026-01-31'),
        ];

        [$text, $pages] = $this->render('estimates.exports.ks3', $data, 'ks3');

        self::assertSame(1, $pages);
        self::assertStringContainsString('Первая строка', $text);
        self::assertStringContainsString('Вторая строка', $text);
        self::assertStringContainsString('100,00', $text);
        self::assertStringContainsString('50,00', $text);
        self::assertStringContainsString('В том числе НДС', $text);
        self::assertStringContainsString('Заказчик:', $text);
        self::assertStringContainsString('Подрядчик:', $text);
    }

    #[WithoutErrorHandler]
    public function test_ks6a_pdf_renders_three_months_and_continuation_pages(): void
    {
        $months = [
            ['key' => '2025-12', 'title' => 'декабрь 2025'],
            ['key' => '2026-01', 'title' => 'январь 2026'],
            ['key' => '2026-02', 'title' => 'февраль 2026'],
        ];
        $rows = collect();
        for ($index = 1; $index <= 12; $index++) {
            $rows->push([
                'number' => $index,
                'estimate_position' => (string) $index,
                'title' => 'Работа '.$index,
                'rate_code' => 'Р-'.$index,
                'unit' => 'м2',
                'unit_price' => 10,
                'estimate_quantity' => 100,
                'estimate_amount' => 1000,
                'performed_quantity' => 60,
                'performed_amount' => 600,
                'remaining_quantity' => 40,
                'remaining_amount' => 400,
                'months' => [
                    '2025-12' => ['quantity' => 10, 'amount' => 100, 'from_start' => 100],
                    '2026-01' => ['quantity' => 20, 'amount' => 200, 'from_start' => 300],
                    '2026-02' => ['quantity' => 30, 'amount' => 300, 'from_start' => 600],
                ],
            ]);
        }

        $data = $this->baseData() + [
            'rows' => $rows,
            'month_groups' => $months,
            'total_estimate_amount' => 12000,
            'total_remaining_amount' => 4800,
            'remaining_label' => 'март 2026',
        ];

        [$text, $pages] = $this->render('estimates.exports.ks6a', $data, 'ks6a');

        self::assertSame(3, $pages);
        self::assertStringContainsString('декабрь 2025', $text);
        self::assertStringContainsString('январь 2026', $text);
        self::assertStringContainsString('февраль 2026', $text);
        self::assertStringContainsString('Работа 12', $text);
    }

    private function render(string $view, array $data, string $name): array
    {
        $bytes = Pdf::loadView($view, $data)
            ->setPaper('a4', 'landscape')
            ->setOption('defaultFont', 'DejaVu Serif')
            ->output();

        $debugDirectory = getenv('MOST_EXPORT_DEBUG_DIR');
        if (is_string($debugDirectory) && $debugDirectory !== '') {
            if (! is_dir($debugDirectory)) mkdir($debugDirectory, 0777, true);
            file_put_contents($debugDirectory.'/'.$name.'.pdf', $bytes);
        }

        $document = (new Parser)->parseContent($bytes);
        return [$document->getText(), count($document->getPages())];
    }

    private function baseData(): array
    {
        $organization = (object) ['name' => 'Заказчик МОСТ', 'legal_name' => 'Заказчик МОСТ', 'tax_number' => '7700000000', 'address' => 'Москва'];
        $contractor = (object) ['name' => 'Подрядчик МОСТ', 'inn' => '7700000001', 'legal_address' => 'Москва'];
        $project = (object) ['name' => 'Проект С-01', 'address' => 'Москва'];
        $contract = (object) ['id' => 1, 'number' => 'Д-01', 'date' => Carbon::parse('2026-01-01'), 'subject' => 'Строительные работы', 'total_amount' => 1000, 'base_amount' => 1000];

        return [
            'act' => (object) ['id' => 1, 'act_document_number' => 'КС-01', 'act_date' => Carbon::parse('2026-01-31')],
            'contract' => $contract,
            'estimate' => null,
            'customer_org' => $organization,
            'contractor' => $contractor,
            'project' => $project,
        ];
    }
}
