<?php

declare(strict_types=1);

namespace Tests\Unit\BudgetEstimates;

use App\BusinessModules\Features\BudgetEstimates\Services\Export\PDFEstimateBuilder;
use App\Models\Estimate;
use Barryvdh\DomPDF\Facade\Pdf;
use DOMDocument;
use DOMXPath;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class EstimatePdfSecurityTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_notes_are_plain_text_and_builder_disables_remote_resources(): void
    {
        $payload = '<img src="http://127.0.0.1:49152/estimate-probe">';
        $document = $this->renderNotes($payload);
        $options = [];
        $pdf = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $pdf->shouldReceive('setPaper')->once()->with('a4', 'portrait')->andReturnSelf();
        $pdf->shouldReceive('setOption')->andReturnUsing(static function (string $name, mixed $value) use (&$options, $pdf) {
            $options[$name] = $value;

            return $pdf;
        });
        $pdf->shouldReceive('output')->once()->andReturn('%PDF-test');
        Pdf::shouldReceive('loadView')->once()->with('estimates.exports.estimate', Mockery::type('array'))->andReturn($pdf);
        $estimate = new Estimate(['number' => 'SEC-1']);
        $result = (new PDFEstimateBuilder)->build($estimate, [
            'estimate' => [], 'sections' => [], 'totals' => [], 'metadata' => [],
        ], []);
        self::assertSame('%PDF-test', $result['content']);
        self::assertSame([0, true, false], [
            $this->activeNodes($document), str_contains($document->textContent, $payload),
            $options['isRemoteEnabled'] ?? null,
        ]);
    }

    #[DataProvider('markup')]
    public function test_parent_and_child_notes_preserve_literal_input(string $payload): void
    {
        $document = $this->renderNotes($payload);

        self::assertSame(0, $this->activeNodes($document));
        self::assertSame(2, substr_count($document->textContent, $payload));
        self::assertStringContainsString('Бетонирование', $document->textContent);
        self::assertStringContainsString('10.0000', $document->textContent);
    }

    public function test_coefficients_escape_keys_values_and_support_calculation_records(): void
    {
        $key = '<img src="http://127.0.0.1:49152/key">';
        $value = '<svg><image href="file:///private-test.svg"/></svg>';
        $document = $this->renderNotes('Обычный текст', [$key => $value]);
        self::assertSame(0, $this->activeNodes($document));
        self::assertStringContainsString($key.'='.$value, $document->textContent);

        $document = $this->renderNotes('Обычный текст', [[
            'id' => 1, 'name' => $key, 'type' => 'conditions', 'value' => 1.2, 'applies_to' => 'total',
        ]]);
        self::assertSame(0, $this->activeNodes($document));
        self::assertStringContainsString($key.'=1.2', $document->textContent);
        self::assertStringContainsString('К:', $document->textContent);
    }

    public static function markup(): iterable
    {
        yield ['<img src="http://127.0.0.1:49152/image">'];
        yield ['</td></tr></table><link rel="stylesheet" href="http://127.0.0.1:49152/style">'];
        yield ['<style>@import url("http://127.0.0.1:49152/import");</style>'];
        yield ['<span style="background-image:url(http://127.0.0.1:49152/background)">текст</span>'];
        yield ['<svg><image href="file:///private-test.svg"/></svg>'];
        yield ['<img src="data:image/svg+xml;base64,PHN2Zy8+">'];
        yield ['&lt;img src=&quot;http://127.0.0.1/test&quot;&gt;'];
        yield ['Кириллица, "кавычки", & и <скобки>'];
    }

    private function renderNotes(string $description, array $coefficients = []): DOMDocument
    {
        $item = [
            'position_number' => '1', 'normative_rate_code' => '01-01', 'is_not_accounted' => false,
            'name' => 'Бетонирование', 'measurement_unit' => 'м3', 'quantity_total' => 10,
            'unit_price' => 120, 'total_amount' => 1200, 'description' => $description,
            'applied_coefficients' => $coefficients, 'child_items' => [],
        ];
        $item['child_items'] = [array_replace($item, ['position_number' => '1.1', 'name' => 'Бетон'])];
        $html = view('estimates.exports.partials.item', [
            'item' => $item, 'indent' => 0, 'options' => ['show_prices' => true, 'include_coefficients' => true],
        ])->render();
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8"><html><body><table>'.$html.'</table></body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);

        return $document;
    }

    private function activeNodes(DOMDocument $document): int
    {
        return (new DOMXPath($document))->query('//img|//link|//style|//svg|//iframe|//object|//embed|//*[@src or @href]|//*[@style[contains(., "url(")]]')->length;
    }
}
