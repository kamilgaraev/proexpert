<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

final class DocumentBrandingRegressionTest extends TestCase
{
    public function test_official_forms_keep_requisites_without_advertising_blocks(): void
    {
        foreach (['ks2', 'ks3', 'ks6a'] as $form) {
            $data = $this->officialFormData();
            $html = view('estimates.exports.'.$form, $data)->render();
            self::assertStringNotContainsString('most-brand-card', $html, $form);
            self::assertStringNotContainsString('Сделано в МОСТ', $html, $form);
            self::assertStringNotContainsString('система управления строительством', mb_strtolower($html), $form);
            self::assertStringNotContainsString('Данные актуальны', $html, $form);
            self::assertStringContainsString('data:image/svg+xml;base64,', $html, $form);
            self::assertSame(1, substr_count($html, 'class="most-document-marker"'), $form);

            $document = $this->render($html, $form);
            $text = preg_replace('/\s+/u', ' ', $document->getText()) ?? '';
            foreach (['Учебный заказчик', 'Учебный подрядчик', 'Д-102', 'Учебная подготовка стен'] as $expected) {
                self::assertStringContainsString($expected, $text, $form);
            }
            foreach ($document->getPages() as $page) {
                self::assertStringContainsString('22.10.2026 09:05 (UTC+03:00)', $page->getText(), $form);
            }
            self::assertLessThanOrEqual($form === 'ks6a' ? 2 : 1, count($document->getPages()), $form);
            self::assertStringContainsString($form === 'ks6a' ? 'Составил' : 'Должность, подпись', $text, $form);
        }
    }

    public function test_marker_repeats_on_long_report_without_adding_a_final_branding_page(): void
    {
        $rows = [];
        for ($index = 1; $index <= 80; $index++) {
            $rows[] = [
                'material_name' => 'Учебный материал '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'material_code' => 'DOC-'.$index,
                'category' => 'Учебная категория',
                'warehouse' => 'Учебный склад №47',
                'available_quantity' => 1,
                'reserved_quantity' => 0,
                'total_quantity' => 1,
                'unit' => 'л',
                'unit_price' => 0,
                'total_value' => 0,
                'is_critical' => false,
            ];
        }
        $html = view('reports.warehouse-stock-pdf', [
            'data' => $rows,
            'generated_at' => '22.10.2026 09:05 (UTC+03:00)',
            'totals' => ['total_items' => 80, 'quantity_by_unit' => [['quantity' => 80, 'unit' => 'л']], 'total_value' => 0, 'critical_items' => 0],
        ])->render();
        self::assertStringNotContainsString('Сделано в МОСТ', $html);
        self::assertStringNotContainsString('most-brand-card', $html);
        $document = $this->render($html, 'warehouse-long', 'portrait');
        self::assertGreaterThan(1, count($document->getPages()));
        foreach ($document->getPages() as $page) {
            self::assertStringContainsString('22.10.2026 09:05 (UTC+03:00)', $page->getText());
            self::assertStringContainsString('Учебный материал', $page->getText());
        }
        self::assertStringContainsString('Учебный материал 80', $document->getText());
    }

    private function render(string $html, string $name, string $orientation = 'landscape'): \Smalot\PdfParser\Document
    {
        $bytes = Pdf::loadHTML($html)->setPaper('a4', $orientation)->setOption('defaultFont', 'DejaVu Serif')->output();
        $debugDirectory = getenv('MOST_EXPORT_DEBUG_DIR');
        if (is_string($debugDirectory) && $debugDirectory !== '') {
            if (! is_dir($debugDirectory)) {
                mkdir($debugDirectory, 0777, true);
            }
            file_put_contents($debugDirectory.'/'.$name.'.pdf', $bytes);
        }

        return (new Parser)->parseContent($bytes);
    }

    private function officialFormData(): array
    {
        $work = ['title' => 'Учебная подготовка стен', 'code' => 'ФЕР15-01', 'quantity' => 100, 'unit' => 'м²', 'unit_price' => 200, 'amount' => 20000];

        return [
            'customer_org' => (object) ['name' => 'Учебный заказчик', 'legal_name' => 'Учебный заказчик', 'tax_number' => '7700000000', 'address' => 'Учебный адрес'],
            'contractor' => (object) ['name' => 'Учебный подрядчик', 'inn' => '7700000001', 'legal_address' => 'Учебная площадка'],
            'project' => (object) ['name' => 'Учебный проект №102', 'address' => 'Учебный адрес'],
            'contract' => (object) ['id' => 317, 'number' => 'Д-102', 'date' => Carbon::parse('2026-10-01'), 'subject' => 'Учебная подготовка стен', 'total_amount' => 20000, 'base_amount' => 20000],
            'act' => (object) ['id' => 160, 'act_document_number' => 'АКТ-102', 'act_date' => Carbon::parse('2026-10-13')],
            'estimate' => null,
            'period_start' => Carbon::parse('2026-10-01'),
            'period_end' => Carbon::parse('2026-10-31'),
            'documentGeneratedAt' => '22.10.2026 09:05 (UTC+03:00)',
            'works' => collect([$work]),
            'total_amount' => 20000,
            'vat_amount' => 0,
            'total_with_vat' => 20000,
            'rows' => collect([[
                'number' => 1, 'estimate_position' => '1', 'title' => $work['title'], 'rate_code' => 'ФЕР15-01',
                'unit' => 'м²', 'unit_price' => 200, 'estimate_quantity' => 100, 'estimate_amount' => 20000,
                'performed_quantity' => 100, 'performed_amount' => 20000, 'remaining_quantity' => 0, 'remaining_amount' => 0,
                'months' => ['2026-10' => ['quantity' => 100, 'amount' => 20000, 'from_start' => 20000]],
            ]]),
            'month_groups' => [['key' => '2026-10', 'title' => 'октябрь 2026']],
            'total_estimate_amount' => 20000,
            'total_remaining_amount' => 0,
            'remaining_label' => 'ноябрь 2026',
        ];
    }
}
