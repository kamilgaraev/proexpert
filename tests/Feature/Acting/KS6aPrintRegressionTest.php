<?php

declare(strict_types=1);

namespace Tests\Feature\Acting;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\OfficialFormsExportService;
use App\Models\Contract;
use App\Models\Contractor;
use App\Models\ContractPerformanceAct;
use App\Models\Organization;
use App\Models\PerformanceActLine;
use App\Models\Project;
use App\Services\Storage\FileService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Tests\Support\ActingTestSchema;
use Tests\TestCase;

final class KS6aPrintRegressionTest extends TestCase
{
    use ActingTestSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActingSchema();
        Storage::fake('s3');
    }

    public function test_compact_journal_keeps_column_headers_with_work_rows(): void
    {
        $contract = $this->createContract();
        $bytes = $this->exportPdf($contract, 'compact');
        $document = (new Parser)->parseContent($bytes);
        $workPages = 0;

        foreach ($document->getPages() as $index => $page) {
            $text = preg_replace('/\s+/u', ' ', $page->getText()) ?? '';
            if (! str_contains($text, 'ПОЗИЦИЯ-')) {
                continue;
            }
            $workPages++;
            self::assertStringContainsString('Конструктивные элементы и виды работ', $text, 'Страница '.($index + 1));
            self::assertStringContainsString('Номер единичной расценки', $text, 'Страница '.($index + 1));
        }

        self::assertSame(1, $workPages, 'Три строки учебного журнала должны помещаться на одной табличной странице');
        self::assertMatchesRegularExpression('/ПОЗИЦИЯ-\s*03/u', $document->getText());
        self::assertStringContainsString('100 000', $document->getText());
    }

    public function test_generated_time_has_organization_timezone_and_offset(): void
    {
        $contract = $this->createContract();
        $this->travelTo(Carbon::parse('2026-10-22 04:28:00', 'UTC'));

        $document = (new Parser)->parseContent($this->exportPdf($contract, 'timezone'));
        $text = preg_replace('/\s+/u', ' ', $document->getText()) ?? '';

        self::assertStringContainsString('22.10.2026 07:28 (UTC+03:00)', $text);
    }

    public function test_contract_number_separators_do_not_remove_form_name_from_download(): void
    {
        $contract = $this->createContract();
        $contract->update(['number' => 'УЧ-ПТО-102/2026']);
        $service = $this->exporter($contract->organization);

        foreach (['pdf', 'xlsx'] as $format) {
            $path = $format === 'pdf'
                ? $service->exportKS6aToPdf($contract)
                : $service->exportKS6aToExcel($contract);
            Storage::disk('s3')->assertExists($path);
            self::assertStringStartsWith('org-'.$contract->organization_id.'/exports/acts/ks6a/', $path);
            self::assertStringStartsWith('KS-6a_УЧ-ПТО-102-2026', basename($path));
            self::assertStringEndsWith('.'.$format, $path);
            self::assertSame(4, substr_count($path, '/'));
        }
    }

    public function test_long_journal_repeats_headers_and_contract_on_each_work_page(): void
    {
        $contract = $this->createContract();
        $act = $contract->performanceActs()->firstOrFail();
        for ($number = 4; $number <= 18; $number++) {
            PerformanceActLine::create([
                'performance_act_id' => $act->id,
                'line_type' => 'manual',
                'title' => 'ПОЗИЦИЯ-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).' Дополнительная учебная работа с длинным описанием поверхности и условий выполнения',
                'unit' => 'м²',
                'quantity' => 1,
                'unit_price' => 10,
                'amount' => 10,
                'manual_reason' => 'Учебная проверка нескольких страниц',
            ]);
        }
        $contract->update(['total_amount' => 100150]);
        $act->update(['amount' => 100150]);
        $document = (new Parser)->parseContent($this->exportPdf($contract, 'long', '2026-10-01', '2026-12-31'));
        $workPages = 0;
        foreach ($document->getPages() as $index => $page) {
            $text = preg_replace('/\s+/u', ' ', $page->getText()) ?? '';
            if (! str_contains($text, 'ПОЗИЦИЯ-')) {
                continue;
            }
            $workPages++;
            self::assertStringContainsString('Конструктивные элементы и виды работ', $text, 'Страница '.($index + 1));
            self::assertStringContainsString('УЧ-ПТО-102-2026', $text, 'Страница '.($index + 1));
        }
        self::assertGreaterThanOrEqual(2, $workPages);
        self::assertMatchesRegularExpression('/ПОЗИЦИЯ-\s*18/u', $document->getText());
        self::assertStringContainsString('100 150', $document->getText());
        self::assertStringContainsString('декабрь 2026', $document->getText());
    }

    private function createContract(): Contract
    {
        $organization = Organization::factory()->create([
            'name' => 'Учебная организация МОСТ',
            'legal_name' => 'Учебная организация МОСТ',
            'address' => 'Условный учебный адрес, длинное название улицы, строение 20А, офис 206',
            'city' => 'Учебный город',
            'postal_code' => '000000',
            'phone' => '+7 (000) 000-00-00',
            'multi_org_settings' => ['default_timezone' => 'Europe/Moscow'],
        ]);
        $project = Project::factory()->create([
            'organization_id' => $organization->id,
            'name' => 'Учебный ПТО — ремонт офиса 100 м² — 13.10.2026',
            'address' => 'Условная площадка учебного проекта №102',
        ]);
        $contractor = Contractor::create([
            'organization_id' => $organization->id,
            'name' => 'Учебный подрядчик ПТО — ремонт офиса',
        ]);
        $contract = Contract::create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'contractor_id' => $contractor->id,
            'number' => 'УЧ-ПТО-102-2026',
            'date' => '2026-10-13',
            'subject' => 'Учебные отделочные работы в офисе площадью 100 м²',
            'total_amount' => 100000,
            'status' => 'active',
        ]);
        $act = ContractPerformanceAct::create([
            'contract_id' => $contract->id,
            'project_id' => $project->id,
            'act_document_number' => 'УЧ-КС-2-01',
            'act_date' => '2026-10-13',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-13',
            'amount' => 100000,
            'status' => ContractPerformanceAct::STATUS_APPROVED,
            'is_approved' => true,
        ]);
        foreach ([
            ['Подготовка поверхности стен под штукатурку (учебная)', 200],
            ['Штукатурка стен гипсовой смесью (учебная)', 500],
            ['Окраска стен водно-дисперсионной краской в два слоя (учебная)', 300],
        ] as $index => [$title, $price]) {
            PerformanceActLine::create([
                'performance_act_id' => $act->id,
                'line_type' => 'manual',
                'title' => 'ПОЗИЦИЯ-0'.($index + 1).' '.$title,
                'unit' => 'м²',
                'quantity' => 100,
                'unit_price' => $price,
                'amount' => 100 * $price,
                'manual_reason' => 'Учебная проверка печатной формы',
            ]);
        }

        return $contract;
    }

    private function exporter(Organization $organization): OfficialFormsExportService
    {
        $files = $this->mock(FileService::class);
        $files->shouldReceive('disk')->withArgs(fn ($scope): bool => $scope instanceof Organization && $scope->id === $organization->id)->andReturn(Storage::disk('s3'));

        return app(OfficialFormsExportService::class);
    }

    private function exportPdf(Contract $contract, string $name, ?string $periodStart = null, ?string $periodEnd = null): string
    {
        $path = $this->exporter($contract->organization)->exportKS6aToPdf($contract, $periodStart, $periodEnd);
        $bytes = Storage::disk('s3')->get($path);
        $debugDirectory = getenv('MOST_EXPORT_DEBUG_DIR');
        if (is_string($debugDirectory) && $debugDirectory !== '') {
            if (! is_dir($debugDirectory)) {
                mkdir($debugDirectory, 0777, true);
            }
            file_put_contents($debugDirectory.'/'.$name.'.pdf', $bytes);
        }

        return $bytes;
    }
}
