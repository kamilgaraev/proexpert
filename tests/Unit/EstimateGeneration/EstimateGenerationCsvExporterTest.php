<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Export\EstimateGenerationCsvExporter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationCsvExporterTest extends TestCase
{
    public function test_stream_keeps_final_rows_literal_and_excludes_unfinished_work(): void
    {
        $item = [
            'name' => 'Бетонирование фундамента', 'item_type' => 'priced_work', 'unit' => 'м3',
            'quantity' => 10, 'total_cost' => 1200, 'quantity_basis' => '=1+1',
            'pricing_status' => 'calculated', 'normative_rate_code' => '01-01-001-01',
            'normative_match' => ['status' => 'matched', 'resources_count' => 1,
                'priced_resources_count' => 1, 'decision' => ['status' => 'accepted']],
            'materials' => [['total_price' => 1200]],
        ];
        $service = new EstimateGenerationCsvExporter(new EstimateGenerationFinalWorkItemGuard);
        $draft = ['local_estimates' => [null, [
            'title' => 'Фундамент', 'sections' => [null, ['title' => 'Бетонные работы', 'work_items' => [
                null, $item, array_replace($item, ['item_type' => 'operation']),
                array_replace($item, ['pricing_status' => 'not_calculated']),
                array_replace($item, ['materials' => []]),
            ]]],
        ]]];
        ob_start();
        try {
            $service->stream($draft);
            $content = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $handle = fopen('php://temp', 'r+');
        try {
            fwrite($handle, $content);
            rewind($handle);
            self::assertSame(['Локальная смета', 'Раздел', 'Работа', 'Ед.', 'Кол-во', 'Итого', 'Основание'], fgetcsv($handle, escape: ''));
            self::assertSame(['Фундамент', 'Бетонные работы', 'Бетонирование фундамента', 'м3', '10', '1200', "'=1+1"], fgetcsv($handle, escape: ''));
            self::assertFalse(fgetcsv($handle, escape: ''));
        } finally {
            fclose($handle);
        }
    }
}
