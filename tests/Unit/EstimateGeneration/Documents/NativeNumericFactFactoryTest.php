<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class NativeNumericFactFactoryTest extends TestCase
{
    public function test_native_room_number_preserves_leading_zeros_and_joins_only_its_parser_room_and_floor(): void
    {
        $native = $this->roomSheet('001');
        $native['cells'][1]['value'] = 'Номер помещения';
        $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $native);
        self::assertSame(['room_number', 'length'], array_column($publication->claims, 'factType'));
        self::assertSame('001', $publication->claims[0]->value['data']);
        self::assertNull($publication->claims[0]->unit);
        self::assertSame('xlsx:sheet:Размеры!C2', $publication->claims[0]->locator['native_reference']);
        self::assertFalse($publication->numericSources->certifies($publication->claims[0]));
        $models = new InMemoryProjectModelRepository;
        (new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository))->writeArbitration(
            $publication->claims, $publication->decisions, 13, 1, $publication->numericSources);
        self::assertCount(1, $models->entities);
        $entity = array_values($models->entities)[0];
        self::assertSame('1', $entity->attributes['floor_id']);
        self::assertArrayNotHasKey('room_number', $entity->attributes);
        self::assertArrayNotHasKey('name', $entity->attributes);
        $number = array_values(array_filter($models->facts, static fn ($fact): bool => $fact->type === 'room_number'))[0];
        self::assertSame('001', $number->value);
        self::assertSame('confirmed', $number->status);
        self::assertSame($entity->id, $number->entityId);
        self::assertNull((new \App\BusinessModules\Addons\EstimateGeneration\Analysis\Arbitration\RoomIdentityObservation)->name($publication->claims[0]));
    }

    public function test_invalid_native_room_number_does_not_become_an_untyped_quantity(): void
    {
        foreach ([['Офис', 'room:office', ''], ['001', 'wall:1', ''], ['001', 'room:office', 'm2'], [1, 'room:office', '']] as [$value, $entity, $unit]) {
            $native = $this->roomSheet($value, $entity, $unit);
            $native['cells'][1]['value'] = 'room_number';
            $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $native);
            self::assertSame(['length'], array_column($publication->claims, 'factType'));
            self::assertSame('native_room_identity_invalid', $publication->quarantinedItems[0]['reason_code']);
        }
    }

    public function test_room_name_shares_the_dimension_entity_and_retains_literal_cell_evidence(): void
    {
        $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $this->roomSheet('Офис'));
        self::assertCount(2, $publication->claims);
        self::assertSame(['room_name', 'length'], array_column($publication->claims, 'factType'));
        self::assertSame($publication->claims[0]->entityKey, $publication->claims[1]->entityKey);
        self::assertSame(['type' => 'string', 'data' => 'Офис'], $publication->claims[0]->value);
        self::assertNull($publication->claims[0]->unit);
        self::assertSame('xlsx:sheet:Размеры!C2', $publication->claims[0]->locator['native_reference']);
        self::assertSame('1', $publication->claims[0]->locator['floor_id']);
        self::assertSame(['accepted', 'accepted'], array_column($publication->decisions, 'status'));
        self::assertFalse($publication->numericSources->certifies($publication->claims[0]));
        self::assertTrue($publication->numericSources->certifies($publication->claims[1]));
        $models = new InMemoryProjectModelRepository;
        (new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository))->writeArbitration(
            $publication->claims, $publication->decisions, 13, 1, $publication->numericSources);
        self::assertCount(1, $models->entities, 'Actual canonical publication must bind the source name and measurement to one room.');
        self::assertCount(1, array_unique(array_column($models->facts, 'entityId')));
        $formula = $this->roomSheet('Офис');
        $formula['cells'][2] = [...$formula['cells'][2], 'formula' => '=OTHER()', 'cached_value' => 'Офис'];
        $decisions = array_column((new NativeNumericFactFactory)->spreadsheet($this->context(), $formula)->decisions, null, 'claimId');
        self::assertSame('candidate', $decisions['literal:native:1']->status);
    }

    public function test_room_name_rejects_non_room_entity_units_and_invalid_literals(): void
    {
        foreach ([['Офис', 'wall:1', ''], ['Офис', 'room:office', 'm'], ['', 'room:office', ''],
            [str_repeat('a', 241), 'room:office', ''], [12, 'room:office', ''], ["Офис\n2", 'room:office', '']] as [$name, $entity, $unit]) {
            $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $this->roomSheet($name, $entity, $unit));
            self::assertSame(['length'], array_column($publication->claims, 'factType'));
            self::assertSame('native_room_identity_invalid', $publication->quarantinedItems[0]['reason_code']);
        }
    }

    public function test_native_room_names_do_not_merge_documents_or_unverified_dimensions(): void
    {
        $factory = new NativeNumericFactFactory;
        $models = new InMemoryProjectModelRepository;
        $writer = new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository);
        foreach ([13, 14] as $document) {
            $publication = $factory->spreadsheet($this->context(documentId: $document), $this->roomSheet('Офис'));
            $writer->writeArbitration($publication->claims, $publication->decisions, $document, 1, $publication->numericSources);
        }
        self::assertCount(2, $models->entities);
        self::assertCount(2, array_unique(array_column($models->facts, 'entityId')));
        $unverifiedModels = new InMemoryProjectModelRepository;
        $publication = $factory->spreadsheet($this->context(), $this->roomSheet('Офис'));
        (new ProjectModelEvidenceWriter($unverifiedModels, new InMemoryEvidenceRepository))->writeArbitration(
            $publication->claims, $publication->decisions, 13, 1);
        self::assertCount(2, $unverifiedModels->entities);
        self::assertCount(2, array_unique(array_column($unverifiedModels->facts, 'entityId')));
    }

    public function test_native_room_identity_metadata_survives_measurement_publication_in_any_row_order(): void
    {
        $rows = [['room:office', 'length', '5', 'м', '1'], ['room:office', 'width', '10', 'м', '1'], ['room:office', 'room_name', 'Офис', '', '1']];
        foreach ([$rows, array_reverse($rows)] as $values) {
            $native = $this->roomSheet('Офис');
            $native['cells'] = [];
            foreach ($values as $row => $columns) {
                foreach ($columns as $column => $value) {
                    $native['cells'][] = ['address' => chr(65 + $column).($row + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
                }
            }
            $publication = (new NativeNumericFactFactory)->spreadsheet($this->context(), $native);
            $models = new InMemoryProjectModelRepository;
            (new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository))->writeArbitration(
                $publication->claims, $publication->decisions, 13, 1, $publication->numericSources);
            self::assertCount(1, $models->entities);
            $entity = array_values($models->entities)[0];
            self::assertSame('Офис', $entity->attributes['name'] ?? null);
            self::assertTrue($entity->attributes['properties']['identity_only'] ?? false);
            self::assertTrue($entity->attributes['properties']['requires_confirmation'] ?? false);
        }
    }

    public function test_named_native_room_revision_preserves_older_numeric_entity_and_fact_records(): void
    {
        $native = $this->roomSheet('Офис');
        $native['cells'] = [];
        $rows = [['room:office', 'length', '5', 'м', '1'], ['room:office', 'width', '10', 'м', '1'], ['room:office', 'room_name', 'Офис', '', '1']];
        foreach ($rows as $row => $values) {
            foreach ($values as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($row + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $legacy = $native;
        $legacy['cells'] = array_slice($native['cells'], 0, 10);
        $models = new InMemoryProjectModelRepository;
        $writer = new ProjectModelEvidenceWriter($models, new InMemoryEvidenceRepository);
        $factory = new NativeNumericFactFactory;
        $old = $factory->spreadsheet($this->context(), $legacy);
        $writer->writeArbitration($old->claims, $old->decisions, 13, 1, $old->numericSources);
        $originalEntity = array_values($models->entities)[0];
        $originalFacts = $models->facts;
        $named = $factory->spreadsheet($this->context(), $native);
        $writer->writeArbitration($named->claims, $named->decisions, 13, 1, $named->numericSources);
        self::assertCount(2, $models->entities);
        self::assertSame($originalEntity, $models->entities[array_key_first($models->entities)]);
        self::assertCount(5, $models->facts);
        foreach ($originalFacts as $key => $fact) {
            self::assertSame($fact, $models->facts[$key]);
        }
    }

    private function roomSheet(mixed $name, string $entity = 'room:office', string $unit = ''): array
    {
        $headers = [];
        foreach (['entity_key', 'parameter', 'value', 'unit', 'floor'] as $index => $value) {
            $headers[] = ['address' => chr(65 + $index).'1', 'value' => $value];
        }
        $cells = [];
        foreach ([[$entity, 'Название помещения', $name, $unit, '1'], ['room:office', 'length', '5', 'м', '1']] as $row => $values) {
            foreach ($values as $column => $value) {
                $cells[] = ['address' => chr(65 + $column).($row + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }

        return ['sheet' => 'Размеры', 'header_cells' => $headers, 'cells' => $cells];
    }

    public function test_engineering_rows_share_only_the_same_source_mark_and_reject_wrong_dimensions(): void
    {
        $headers = [['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
            ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.']];
        $cells = [];
        foreach ([['equipment.p1', 'equipment_specification', 'Насос P1, модель и комплектность', ''],
            ['equipment.p1', 'Количество оборудования', '3', 'шт'],
            ['water_pipe.w1', 'Длина трубопровода', '12500', 'mm'],
            ['electrical_circuit.e1', 'Длина кабеля', '40', 'm'],
            ['equipment.p2', 'equipment_count', '1.5', 'шт'],
            ['water_pipe.w2', 'pipe_length', '25', 'm2'],
            ['equipment:', 'equipment_count', '2', 'шт']] as $index => $row) {
            foreach ($row as $column => $value) {
                $cells[] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $native = ['sheet' => 'Spec', 'header_cells' => $headers, 'cells' => $cells];
        $factory = new NativeNumericFactFactory;
        $publication = $factory->spreadsheet($this->context(), $native);
        self::assertCount(4, $publication->claims);
        self::assertSame($publication->claims[0]->entityKey, $publication->claims[1]->entityKey);
        self::assertSame('equipment.p1', $publication->claims[1]->locator['source_entity_key']);
        self::assertTrue($publication->numericSources->certifies($publication->claims[1]));
        self::assertSame(['native_engineering_measurement_invalid', 'native_engineering_measurement_invalid', 'native_engineering_measurement_invalid'],
            array_column($publication->quarantinedItems, 'reason_code'));
        $context = $this->context();
        $other = new DocumentUnitExecutionContext($context->unitId, $context->organizationId, $context->projectId,
            $context->sessionId, $context->documentId + 1, $context->type, $context->index, $context->sourceVersion,
            [], 'source.xlsx', 'application/octet-stream', 'source.xlsx', 'claim-token', 1, 0, 'draft', 17);
        self::assertNotSame($publication->claims[1]->entityKey, $factory->spreadsheet($other, $native)->claims[1]->entityKey);
    }

    public function test_specification_text_is_published_with_cell_evidence_but_never_as_a_numeric_measurement(): void
    {
        $native = ['sheet' => 'Спецификация', 'header_cells' => [
            ['address' => 'A1', 'value' => 'Объект'], ['address' => 'B1', 'value' => 'Параметр'],
            ['address' => 'C1', 'value' => 'Значение'], ['address' => 'D1', 'value' => 'Ед. изм.']], 'cells' => []];
        $text = 'Спецификация насоса P1: модель, комплектация и требования монтажа.';
        foreach ([['equipment.p1', 'Спецификация оборудования', $text, ''],
            ['roof.1', 'heating_test_program', 'Программа испытаний другой системы.', '']] as $index => $row) {
            foreach ($row as $column => $value) {
                $native['cells'][] = ['address' => chr(65 + $column).($index + 2), 'value' => $value, 'raw_value' => $value, 'formula' => null];
            }
        }
        $factory = new NativeNumericFactFactory;
        $publication = $factory->spreadsheet($this->context(), $native);
        self::assertCount(1, $publication->claims);
        self::assertSame(['type' => 'string', 'data' => $text], $publication->claims[0]->value);
        self::assertNull($publication->claims[0]->unit);
        self::assertSame('xlsx:sheet:Спецификация!C2', $publication->claims[0]->locator['native_reference']);
        self::assertSame('accepted', $publication->decisions[0]->status);
        self::assertSame('native_document_context_invalid', $publication->quarantinedItems[0]['reason_code']);
        self::assertFalse($publication->numericSources->certifies($publication->claims[0]));
        $native['cells'][2] = [...$native['cells'][2], 'formula' => '=OTHER()', 'cached_value' => $text];
        self::assertSame('candidate', $factory->spreadsheet($this->context(), $native)->decisions[0]->status);
    }

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

    private function context(DocumentUnitType $type = DocumentUnitType::SpreadsheetSheet, int $documentId = 13): DocumentUnitExecutionContext
    {
        return new DocumentUnitExecutionContext(1, 7, 9, 11, $documentId, $type, 1, 'sha256:'.str_repeat('a', 64),
            [], 'source.xlsx', 'application/octet-stream', 'source.xlsx', 'claim-token', 1, 0, 'draft', 17);
    }
}
