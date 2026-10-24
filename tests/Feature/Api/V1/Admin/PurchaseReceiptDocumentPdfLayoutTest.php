<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Services\PurchaseReceiptDocumentPdfService;
use Barryvdh\DomPDF\PDF as PdfRenderer;
use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class PurchaseReceiptDocumentPdfLayoutTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_torg12_has_fifteen_columns_and_preserves_the_package_quantity(): void
    {
        $html = view('procurement.receipt-document', ['document' => $this->document(), 'order' => new PurchaseOrder()])->render();
        $dom = new DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);

        $numbers = $xpath->query('//table[@class="items"]/thead/tr[@class="hint"]/th');
        self::assertSame(15, $numbers->length);
        self::assertSame('15', trim($numbers->item(14)->textContent));
        $cells = $xpath->query('//table[@class="items"]/tbody/tr[1]/td');
        self::assertSame('3', trim($cells->item(6)->textContent));
        self::assertSame('2', trim($cells->item(7)->textContent));
        self::assertSame('6.4', trim($cells->item(8)->textContent));
        self::assertStringContainsString('в одном месте', $html);
    }

    public function test_required_form_fields_and_signature_dates_do_not_invent_historical_facts(): void
    {
        Carbon::setTestNow('2043-07-16 12:00:00');
        $html = view('procurement.receipt-document', ['document' => $this->document(), 'order' => new PurchaseOrder()])->render();
        foreach (['Транспортная накладная', 'Товарная накладная имеет приложение', 'порядковых номеров записей', 'Масса груза (нетто)', 'Всего мест', 'Масса груза (брутто)', 'По доверенности', 'Всего отпущено на сумму'] as $label) {
            self::assertStringContainsString($label, $html);
        }
        self::assertStringContainsString('17.10.2021', $html);
        self::assertStringNotContainsString('2043', strip_tags($html));
        self::assertStringContainsString('Десять тысяч пятьсот', $html);
        self::assertStringContainsString('Один', $html);
    }

    public function test_amount_in_words_keeps_exact_decimal_kopecks_and_does_not_invent_unknown_totals(): void
    {
        $document = $this->document();
        $document['totals']['amount_with_vat'] = '9999999999999.99';
        $html = view('procurement.receipt-document', ['document' => $document, 'order' => new PurchaseOrder()])->render();
        self::assertStringContainsString('Девять триллионов девятьсот девяносто девять миллиардов', $html);
        self::assertStringContainsString('руб. 99 коп.', $html);
        $document['totals']['amount_with_vat'] = 0.01;
        $html = view('procurement.receipt-document', ['document' => $document, 'order' => new PurchaseOrder()])->render();
        self::assertStringContainsString('Ноль руб. 01 коп.', $html);
        unset($document['totals']['amount_with_vat']);
        $html = view('procurement.receipt-document', ['document' => $document, 'order' => new PurchaseOrder()])->render();
        self::assertStringNotContainsString('Ноль руб. 00 коп.', $html);
    }

    public function test_real_pdf_renders_short_long_and_large_value_documents(): void
    {
        $rendered = null;
        $this->app->resolving('dompdf.wrapper', static function (PdfRenderer $pdf) use (&$rendered): void {
            $rendered = $pdf;
        });
        $short = $this->document();
        $long = $this->document(45);
        $large = $this->document();
        $large['rows'][0]['quantity_in_package'] = null;
        $large['rows'][0]['places_count'] = null;
        $large['rows'][0]['gross_weight'] = null;
        $large['rows'][0]['quantity'] = '1';
        $large['rows'][0]['price'] = '9999999999999.99';
        $large['rows'][0]['amount_without_vat'] = '9999999999999.99';
        $large['rows'][0]['amount_with_vat'] = '9999999999999.99';
        $large['totals']['quantity'] = '1';
        $large['totals']['amount_without_vat'] = '9999999999999.99';
        $large['totals']['amount_with_vat'] = '9999999999999.99';

        foreach (['short' => $short, 'long' => $long, 'large' => $large] as $name => $document) {
            $order = new PurchaseOrder();
            $order->id = 12;
            $order->order_number = 'УЧ-12';
            $response = app(PurchaseReceiptDocumentPdfService::class)->download($order, $document);
            self::assertSame(200, $response->getStatusCode());
            self::assertStringStartsWith('application/pdf', $response->headers->get('Content-Type'));
            self::assertStringStartsWith('%PDF-', $response->getContent());
            $directory = getenv('MOST_TORG12_QA_OUTPUT');
            if (is_string($directory) && $directory !== '') {
                self::assertDirectoryExists($directory);
                self::assertGreaterThan(1000, file_put_contents($directory.'/'.$name.'.pdf', $response->getContent()));
                file_put_contents($directory.'/'.$name.'.html', view('procurement.receipt-document', ['document' => $document, 'order' => $order])->render());
            }
            self::assertInstanceOf(PdfRenderer::class, $rendered);
            $pageCount = $rendered->getDomPDF()->getCanvas()->get_page_count();
            if ($name === 'long') {
                self::assertGreaterThan(1, $pageCount);
            } else {
                self::assertSame(1, $pageCount, $name.' should fit on one page');
            }
        }
    }

    private function document(int $count = 1): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'row_number' => $i, 'name' => 'Учебная краска для проверки формы, строка '.$i,
                'code' => 'TRAINING-PAINT-'.$i, 'unit_name' => 'л', 'okei_code' => '112',
                'package_type' => 'Банка', 'quantity_in_package' => '3', 'places_count' => '2',
                'gross_weight' => '6.4', 'quantity' => '6', 'price' => '1750.00',
                'amount_without_vat' => '10500.00', 'vat_rate' => null, 'vat_amount' => '0.00',
                'amount_with_vat' => '10500.00',
            ];
        }

        return [
            'title' => 'Товарная накладная', 'document_number' => 'УЧ-Т12-2021',
            'document_date' => '2021-10-17', 'okud' => '0330212', 'operation_type' => 'Учебная проверка',
            'supplier' => ['name' => 'Учебный поставщик', 'inn' => '0000000000', 'address' => 'Условный адрес'],
            'consignee' => ['name' => 'Учебный получатель', 'inn' => '1111111111', 'address' => 'Условная площадка'],
            'basis' => ['document_type' => 'Учебный заказ', 'number' => 'УЧ-12', 'date' => '2021-10-16'],
            'rows' => $rows,
            'totals' => ['rows_count' => $count, 'quantity' => (string) (6 * $count), 'amount_without_vat' => (string) (10500 * $count), 'vat_amount' => '0.00', 'amount_with_vat' => (string) (10500 * $count)],
        ];
    }
}
