<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use PHPUnit\Framework\TestCase;

final class NativeNumericFactFactoryTest extends TestCase
{
    public function test_sheet_literals_have_exact_cell_refs_and_cached_formula_is_only_a_candidate(): void
    {
        $native = $this->sheet();
        $native['cells'][] = ['address' => 'B3', 'value' => '=B2*2', 'raw_value' => '=B2*2', 'formula' => '=B2*2', 'cached_value' => 6];
        $native['cells'][] = ['address' => 'C3', 'value' => 'м³'];
        $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $native);
        self::assertCount(2, $publication?->claims ?? []);
        $decisions = array_column($publication?->decisions ?? [], null, 'claimId');
        self::assertSame('accepted', $decisions['literal:native:1']->status);
        self::assertSame('candidate', $decisions['literal:native:2']->status);
        self::assertSame('3', $publication?->claims[0]->value['data']);
        self::assertSame('м³', $publication?->claims[0]->unit);
        self::assertSame('xlsx:sheet:Объемы!B2', $publication?->claims[0]->locator['native_reference']);
        self::assertNotSame($publication?->claims[0]->entityKey, $publication?->claims[1]->entityKey);
    }

    public function test_unsupported_units_and_missing_formula_cache_do_not_become_zero(): void
    {
        $native = $this->sheet();
        $native['cells'][1]['value'] = '';
        $native['cells'][] = ['address' => 'B3', 'value' => '=B2*2', 'formula' => '=B2*2', 'cached_value' => null];
        $native['cells'][] = ['address' => 'C3', 'value' => 'm3'];
        $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $native);
        self::assertSame([], $publication?->claims);
        self::assertSame(['native_numeric_unit_missing', 'native_numeric_value_missing'], array_column($publication?->quarantinedItems ?? [], 'reason_code'));
    }

    public function test_only_confirmed_model_space_dimensions_without_block_or_transform_are_accepted(): void
    {
        $dimension = ['handle' => 'AB', 'layout' => 'Model', 'dimension_type' => 0, 'measurement' => 3000, 'measurement_space' => 'entity_coordinates'];
        $geometry = ['source_unit' => 'mm', 'unit_status' => 'confirmed', 'dimensions' => [$dimension]];
        $factory = new NativeNumericFactFactory;
        self::assertSame('accepted', $factory->cad($this->context(DocumentUnitType::CadDrawing), $geometry)?->decisions[0]->status);
        foreach (['block' => 'block:1', 'transform' => [1, 0, 0, 1], 'layout' => 'Layout1'] as $key => $value) {
            $geometry['dimensions'] = [[...$dimension, $key => $value]];
            self::assertSame('candidate', $factory->cad($this->context(DocumentUnitType::CadDrawing), $geometry)?->decisions[0]->status);
        }
        $geometry['dimensions'] = [[...$dimension, 'dimension_type' => 2]];
        self::assertNull($factory->cad($this->context(DocumentUnitType::CadDrawing), $geometry));
        $geometry['dimensions'] = [$dimension];
        $geometry['source_unit'] = 'unknown';
        self::assertSame([], $factory->cad($this->context(DocumentUnitType::CadDrawing), $geometry)?->claims);
    }

    private function sheet(): array
    {
        return ['sheet' => 'Объемы', 'header_cells' => [
            ['address' => 'B1', 'value' => 'Количество'], ['address' => 'C1', 'value' => 'Ед. изм.'],
        ], 'cells' => [
            ['address' => 'B2', 'value' => '3', 'raw_value' => 3, 'formula' => null], ['address' => 'C2', 'value' => 'м³'],
        ]];
    }

    public function test_russian_parameters_and_zero_roof_rise_are_typed_but_unknown_parameter_is_quarantined(): void
    {
        $headers = [['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'], ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.']];
        $cells = [];
        foreach ([['room:1', 'Площадь помещения', '25.97', 'м²'], ['roof:1', 'Подъём ската', '0', 'м'], ['room:1', 'Неизвестный параметр', '1', 'м']] as $index => $values) {
            foreach ($values as $column => $value) {
                $cells[] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), ['sheet' => 'Размеры', 'header_cells' => $headers, 'cells' => $cells]);
        self::assertSame(['area', 'slope_rise'], array_column($publication?->claims ?? [], 'factType'));
        self::assertSame(['accepted', 'accepted'], array_column($publication?->decisions ?? [], 'status'));
        self::assertSame('0', $publication?->claims[1]->value['data']);
        self::assertSame('native_parameter_missing', $publication?->quarantinedItems[0]['reason_code']);
    }

    private function context(DocumentUnitType $type = DocumentUnitType::SpreadsheetSheet): DocumentUnitExecutionContext
    {
        return new DocumentUnitExecutionContext(1, 7, 9, 11, 13, $type, 1, 'sha256:'.str_repeat('a', 64),
            [], 'source.xlsx', 'application/octet-stream', 'source.xlsx', 'claim-token', 1, 0, 'draft', 17);
    }
}
