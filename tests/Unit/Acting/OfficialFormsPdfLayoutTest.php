<?php

declare(strict_types=1);

namespace Tests\Unit\Acting;

use Dompdf\Dompdf;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\CompilerEngine;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PHPUnit\Framework\TestCase;

final class OfficialFormsPdfLayoutTest extends TestCase
{
    public function test_short_forms_fit_one_page_and_long_forms_keep_all_work_rows(): void
    {
        $previousContainer = Container::getInstance();
        $container = new Container;
        Container::setInstance($container);
        $files = new Filesystem;
        $cache = sys_get_temp_dir().'/most-pdf-layout-'.bin2hex(random_bytes(8));
        $files->makeDirectory($cache);

        try {
            $compiler = new BladeCompiler($files, $cache);
            $engines = new EngineResolver;
            $engines->register('blade', fn () => new CompilerEngine($compiler, $files));
            $views = new Factory($engines, new FileViewFinder($files, [dirname(__DIR__, 3).'/resources/views']), new Dispatcher($container));
            $views->setContainer($container);
            $container->instance('view', $views);

            foreach ([[1, false], [40, false], [1, true]] as [$count, $longDetails]) {
                foreach (['ks2', 'ks3'] as $form) {
                    $data = [
                        'documentGeneratedAt' => '04.09.2026 09:00',
                        'act' => (object) ['act_date' => '2026-08-29', 'act_document_number' => 'КС-2-ТЕСТ-01'],
                        'period_start' => '2026-08-01', 'period_end' => '2026-08-29',
                        'customer_org' => (object) ['name' => 'Заказчик', 'address' => 'г. Казань, улица Строителей, дом 10'],
                        'contractor' => (object) ['name' => 'Подрядчик'],
                        'project' => (object) ['name' => 'Производственный корпус'],
                        'contract' => (object) ['number' => 'ДП-202608-34', 'date' => '2026-08-28'],
                        'contract_amount' => 5715 * $count, 'total_amount' => 5715 * $count,
                        'works' => array_map(fn ($i) => ['title' => 'Монтаж арматуры RowMarker'.$i.'End', 'quantity' => 5, 'unit' => 'кг', 'unit_price' => 1143, 'amount' => 5715], range(1, $count)),
                    ];
                    if ($longDetails) {
                        $data['customer_org']->name = 'Общество с ограниченной ответственностью «Производственно-строительная компания комплексного промышленного строительства»';
                        $data['customer_org']->address = 'Республика Татарстан, город Казань, улица Производственная, дом 123, корпус 4, помещение 567, промышленная площадка Северная';
                        $data['contractor']->name = 'Общество с ограниченной ответственностью «Специализированное управление монтажных и строительных работ»';
                        $data['project']->name = 'Строительство многофункционального производственно-складского комплекса с административно-бытовыми помещениями и инженерными сетями';
                    }
                    $pdf = new Dompdf(['defaultFont' => 'DejaVu Serif']);
                    $rendered = '';
                    $pageText = [];
                    $boxes = [];
                    $pdf->setCallbacks([['event' => 'end_frame', 'f' => static function ($frame, $canvas) use (&$rendered, &$boxes, &$pageText): void {
                        if ($frame->get_node()->nodeName === '#text') {
                            $rendered .= $frame->get_node()->textContent;
                            $page = $canvas->get_page_number();
                            $pageText[$page] = ($pageText[$page] ?? '').$frame->get_node()->textContent;
                        }
                        if ($frame->get_node() instanceof \DOMElement) {
                            $class = $frame->get_node()->getAttribute('class');
                            if (in_array($class, ['code-area', 'document-block'], true)) {
                                $boxes[$class] = [$canvas->get_page_number(), $frame->get_border_box()];
                            }
                        }
                    }]]);
                    $pdf->loadHtml($views->make('estimates.exports.'.$form, $data)->render());
                    $pdf->setPaper('a4', 'landscape');
                    $pdf->render();
                    self::assertArrayHasKey('code-area', $boxes);
                    self::assertArrayHasKey('document-block', $boxes);
                    self::assertSame($boxes['code-area'][0], $boxes['document-block'][0], $form);
                    self::assertGreaterThanOrEqual(
                        $boxes['code-area'][1]['y'] + $boxes['code-area'][1]['h'],
                        $boxes['document-block'][1]['y'],
                        $form.' header overlaps document metadata',
                    );
                    if ($count === 1 && ! $longDetails) {
                        self::assertSame(1, $pdf->getCanvas()->get_page_count(), $form);
                    } elseif ($count > 1) {
                        self::assertGreaterThan(1, $pdf->getCanvas()->get_page_count(), $form);
                    }
                    foreach (range(1, $count) as $i) {
                        self::assertStringContainsString('RowMarker'.$i.'End', $rendered, $form);
                    }
                    if ($form === 'ks2') {
                        foreach ($pageText as $text) {
                            if (str_contains($text, 'Наименование работ')) {
                                self::assertStringContainsString('по порядку', $text, 'КС-2: шапка таблицы разорвана между страницами');
                            }
                        }
                    }
                }
            }
        } finally {
            Container::setInstance($previousContainer);
            $files->deleteDirectory($cache);
        }
    }
}
