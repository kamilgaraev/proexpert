<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractDocumentExporter;
use Smalot\PdfParser\Parser;
use Tests\TestCase;
use ZipArchive;

final class ContractWorkSpecificationLayoutTest extends TestCase
{
    public function test_pdf_keeps_project_amounts_on_single_lines(): void
    {
        $text = $this->pdfText($this->specification(), 'project-102');

        foreach (['20 000,00', '50 000,00', '30 000,00', '100 000,00', '200,0000', '500,0000', '300,0000'] as $amount) {
            self::assertStringContainsString($amount, $text, 'The complete amount must remain on one PDF line.');
        }
        foreach (['20 000,00', '50 000,00', '30 000,00'] as $amount) {
            self::assertSame(2, substr_count($text, $amount), 'Both net and gross row amounts must remain complete.');
        }
        self::assertStringContainsString('СМ-2026-0017', $text);
        self::assertStringContainsString('100 м²', $text);
    }

    public function test_pdf_keeps_large_amounts_with_vat_and_long_work_names_readable(): void
    {
        $data = $this->specification();
        $data['contract']['amount'] = '148148146814.81';
        $data['rows'] = [array_replace($data['rows'][0], [
            'name' => 'Подготовка поверхности наружных стен здания под нанесение многослойного защитного покрытия с выравниванием основания и обработкой труднодоступных участков',
            'unit_price_without_vat' => '1234567890.1234',
            'amount_without_vat' => '123456789012.34',
            'amount_with_vat' => '148148146814.81',
            'vat_mode' => 'rate',
            'vat_rate' => '20',
        ])];
        $data['totals'] = ['amount_without_vat' => '123456789012.34', 'vat_amount' => '24691357802.47', 'amount_with_vat' => '148148146814.81'];
        $text = $this->pdfText($data, 'large-vat');

        foreach (['1 234 567 890,1234', '123 456 789 012,34', '148 148 146 814,81', '24 691 357 802,47'] as $amount) {
            self::assertStringContainsString($amount, $text, 'A large amount must remain on one PDF line.');
        }
        self::assertStringContainsString('труднодоступных участков', preg_replace('/\s+/u', ' ', $text) ?? '');
        self::assertStringContainsString('20 %', $text);
    }

    public function test_unpriced_rows_keep_explanations_and_docx_remains_exportable(): void
    {
        $data = $this->specification();
        $data['contract']['amount'] = null;
        $data['rows'] = [array_replace($data['rows'][0], [
            'unit_price_without_vat' => null,
            'amount_without_vat' => null,
            'amount_with_vat' => null,
            'composition_confirmed' => false,
        ])];
        $data['totals'] = ['amount_without_vat' => null, 'vat_amount' => null, 'amount_with_vat' => null];
        $text = $this->pdfText($data, 'unpriced');
        $flowText = preg_replace('/\s+/u', ' ', $text) ?? '';
        self::assertStringContainsString(trans_message('estimate_finance.specification_unpriced'), $flowText);
        self::assertStringContainsString('Состав цены требует подтверждения.', $flowText);

        $bytes = (new ContractDocumentExporter)->render(view('contracts.work-specification', ['specification' => $this->specification()])->render(), 'docx');
        $path = tempnam(sys_get_temp_dir(), 'most-specification-');
        self::assertIsString($path);
        try {
            file_put_contents($path, $bytes);
            $zip = new ZipArchive;
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('word/document.xml');
            self::assertIsString($xml);
            $zip->close();
            $wordText = $this->normalize(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            foreach (['20 000,00', '50 000,00', '30 000,00', '100 000,00'] as $amount) {
                self::assertStringContainsString($amount, $wordText);
            }
        } finally {
            unlink($path);
        }
    }

    private function pdfText(array $specification, string $name): string
    {
        $bytes = (new ContractDocumentExporter)->render(view('contracts.work-specification', compact('specification'))->render(), 'pdf');
        $artifactDirectory = getenv('MOST_SPECIFICATION_QA_DIR');
        if ($artifactDirectory !== false && is_dir($artifactDirectory)) {
            file_put_contents($artifactDirectory.'/specification-layout-'.$name.'.pdf', $bytes);
        }

        $document = (new Parser)->parseContent($bytes);
        self::assertCount(1, $document->getPages(), 'Each representative specification should fit on one A4 page.');

        return $this->normalize($document->getText());
    }

    private function normalize(string $text): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $text);
    }

    private function specification(): array
    {
        $names = ['Подготовка поверхности стен под штукатурку (учебная)', 'Штукатурка стен гипсовой смесью (учебная)', 'Окраска стен водно-дисперсионной краской в два слоя (учебная)'];
        $amounts = [20000, 50000, 30000];
        $rows = [];
        foreach ($names as $index => $name) {
            $rows[] = [
                'position_number' => (string) ($index + 1), 'name' => $name, 'estimate_number' => 'СМ-2026-0017',
                'quantity' => '100', 'unit' => 'м²', 'unit_price_without_vat' => $amounts[$index] / 100,
                'amount_without_vat' => $amounts[$index], 'amount_with_vat' => $amounts[$index],
                'vat_mode' => 'none', 'vat_rate' => null, 'currency' => 'RUB', 'composition_confirmed' => true,
            ];
        }

        return [
            'contract' => ['number' => 'УЧ-ПТО-102/2026', 'date' => '01.10.2026', 'currency' => 'RUB', 'amount' => '100000.00'],
            'rows' => $rows,
            'totals' => ['amount_without_vat' => '100000.00', 'vat_amount' => '0.00', 'amount_with_vat' => '100000.00'],
        ];
    }
}
