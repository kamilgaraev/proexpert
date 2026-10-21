<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Export\EstimateGenerationCsvExporter;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationFinalWorkItemGuard;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationCsvExporterTest extends TestCase
{
    public function test_catalog_normative_and_mixed_rows_keep_seven_columns_and_net_money(): void
    {
        $price = ['source_type' => 'catalog', 'verified' => true, 'source_reference' => 'catalog:1', 'source_hash' => str_repeat('a', 64),
            'currency' => 'RUB', 'vat_mode' => 'included', 'vat_rate' => '20', 'conditions_missing' => []];
        $commercial = ['key' => 'commercial', 'name' => '=Коммерческая работа', 'item_type' => 'confirmed_commercial_work',
            'quantity' => '1', 'unit' => 'm2', 'total_cost' => '50.00', 'commercial_price_snapshot' => $price,
            'accepted_source_money' => (new \App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection)->amount('60.00', $price),
            'metadata' => ['confirmed_commercial_position' => true, 'evaluation_confirmation' => ['scenario_id' => 'maximum']]];
        $normative = ['key' => 'normative', 'name' => 'Нормативная работа', 'item_type' => 'priced_work', 'unit' => 'm2',
            'quantity' => '2', 'total_cost' => '80.00', 'pricing_status' => 'calculated', 'normative_rate_code' => '11-01-001-01',
            'normative_match' => ['status' => 'matched', 'resources_count' => 1, 'priced_resources_count' => 1, 'decision' => ['status' => 'accepted']],
            'materials' => [['total_price' => '80.00']]];
        $invalid = [...$commercial, 'total_cost' => '60.00'];
        $unverified = [...$commercial, 'commercial_price_snapshot' => [...$price, 'verified' => false]];
        foreach ([[$commercial], [$normative], [$commercial, $normative]] as $items) {
            ob_start();
            try {
                (new EstimateGenerationCsvExporter(new EstimateGenerationFinalWorkItemGuard))->stream([
                    'local_estimates' => [['title' => 'Смета', 'sections' => [
                        ['title' => 'Раздел', 'work_items' => [...$items, $invalid, $unverified]],
                    ]]],
                ]);
                $content = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $handle = fopen('php://temp', 'r+');
            try {
                fwrite($handle, $content);
                rewind($handle);
                self::assertCount(7, fgetcsv($handle, escape: ''));
                foreach ($items as $item) {
                    $row = fgetcsv($handle, escape: '');
                    self::assertIsArray($row);
                    self::assertCount(7, $row);
                    self::assertSame($item['quantity'], $row[4]);
                    self::assertSame($item['total_cost'], $row[5]);
                    self::assertSame($item['key'] === 'commercial' ? "'=Коммерческая работа" : $item['name'], $row[2]);
                }
                self::assertFalse(fgetcsv($handle, escape: ''));
            } finally {
                fclose($handle);
            }
        }
    }

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
