<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeNumericFactFactory;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\NativeSpreadsheetLiteralSources;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSchema;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class NativeSpreadsheetLiteralSourcesTest extends TestCase
{
    public function test_only_the_typed_artifact_channel_attests_a_literal_and_preserves_numeric_guards(): void
    {
        $native = $this->native();
        $content = $this->content($native);
        $context = $this->context($content);
        $factory = new NativeNumericFactFactory;
        $ordinary = $factory->spreadsheet($context, [...$native, 'native_literal_source' => ['producer' => 'native_spreadsheet_literal:v1']]);
        self::assertNull($ordinary->literalSources);
        $witness = NativeSpreadsheetLiteralSources::fromArtifact($context, $content);
        self::assertNotNull($witness);
        $publication = $factory->spreadsheet($context, $native, $witness);
        $claim = $publication->claims[0];
        $binding = $publication->literalSources->binding($claim, 13, 1);
        self::assertSame('native_spreadsheet_literal:v1', $binding['producer']);
        self::assertSame('C2', $binding['cell']);
        self::assertSame('sha256:'.hash('sha256', $content), $binding['artifact_sha256']);
        self::assertFalse($publication->numericSources->certifies($claim));
        self::assertNull($publication->literalSources->binding($claim, 99, 1));
        self::assertNull($publication->literalSources->binding($claim, 13, 2));
        $models = new InMemoryProjectModelRepository;
        $evidence = new InMemoryEvidenceRepository;
        (new ProjectModelEvidenceWriter($models, $evidence))->writeArbitration($publication->claims, $publication->decisions, 13, 1,
            $publication->numericSources, $publication->literalSources);
        $node = $evidence->node(11, 12, 14, 1);
        self::assertSame(CanonicalPipelineJson::encode($binding), CanonicalPipelineJson::encode($node->value['native_literal_source']));
        self::assertSame([], array_values($models->facts)[0]->nativeNumericProofs);
        $ordinaryEvidence = new InMemoryEvidenceRepository;
        (new ProjectModelEvidenceWriter(new InMemoryProjectModelRepository, $ordinaryEvidence))->writeArbitration(
            $ordinary->claims, $ordinary->decisions, 13, 1, $ordinary->numericSources);
        self::assertArrayNotHasKey('native_literal_source', $ordinaryEvidence->node(11, 12, 14, 1)->value);
    }

    #[DataProvider('unsupportedCells')]
    public function test_formulas_and_nonliteral_identity_cells_do_not_mint_a_binding(string $address, array $change): void
    {
        $native = $this->native();
        foreach ($native['cells'] as &$cell) {
            if ($cell['address'] === $address) {
                $cell = [...$cell, ...$change];
            }
        }
        unset($cell);
        $content = $this->content($native);
        $context = $this->context($content);
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, $native,
            NativeSpreadsheetLiteralSources::fromArtifact($context, $content));
        self::assertNotNull($publication);
        foreach ($publication->claims as $claim) {
            self::assertNull($publication->literalSources->binding($claim, 13, 1));
        }
    }

    public static function unsupportedCells(): array
    {
        return [
            'value formula' => ['C2', ['formula' => '="Насос"', 'cached_value' => 'Насос']],
            'missing raw value' => ['C2', ['raw_value' => null]],
            'entity formula' => ['A2', ['formula' => '="equipment:pump"']],
            'parameter formula' => ['B2', ['formula' => '="equipment_specification"']],
            'unit formula' => ['D2', ['formula' => '=""']],
            'entity formatted alias' => ['A2', ['raw_value' => 'equipment:other']],
            'parameter formatted alias' => ['B2', ['raw_value' => 'other_parameter']],
        ];
    }

    #[DataProvider('invalidArtifacts')]
    public function test_digest_source_scope_and_artifact_kind_are_fail_closed(array $locator, DocumentUnitType $type): void
    {
        $content = $this->content($this->native());
        $this->expectException(DocumentUnitProcessingException::class);
        NativeSpreadsheetLiteralSources::fromArtifact($this->context($content, $locator, $type), $content);
    }

    public static function invalidArtifacts(): array
    {
        return [
            'digest' => [['artifact_sha256' => 'sha256:'.str_repeat('0', 64)], DocumentUnitType::SpreadsheetSheet],
            'bytes' => [['artifact_bytes' => 1], DocumentUnitType::SpreadsheetSheet],
            'source version' => [['source_version' => 'stale'], DocumentUnitType::SpreadsheetSheet],
            'organization' => [['artifact_path' => 'org-99/fake'], DocumentUnitType::SpreadsheetSheet],
            'observer artifact' => [['artifact_kind' => 'drawing_observations'], DocumentUnitType::SpreadsheetSheet],
            'ocr source' => [['source_kind' => 'ocr'], DocumentUnitType::SpreadsheetSheet],
            'image unit' => [[], DocumentUnitType::RasterImage],
        ];
    }

    public function test_changed_native_content_and_foreign_claim_scope_cannot_reuse_the_witness(): void
    {
        $native = $this->native();
        $content = $this->content($native);
        $witness = NativeSpreadsheetLiteralSources::fromArtifact($this->context($content), $content);
        foreach (['content', 'organization', 'project', 'session', 'document', 'version', 'unit'] as $change) {
            $context = $this->context($content, [], DocumentUnitType::SpreadsheetSheet, $change);
            $changed = $native;
            if ($change === 'content') {
                $changed['cells'][2]['raw_value'] = 'Другое оборудование';
            }
            try {
                (new NativeNumericFactFactory)->spreadsheet($context, $changed, $witness);
                self::fail($change.' must not reuse a source witness');
            } catch (DocumentUnitProcessingException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_closed_binding_shape_is_not_a_general_evidence_metadata_escape_hatch(): void
    {
        $native = $this->native();
        $content = $this->content($native);
        $context = $this->context($content);
        $publication = (new NativeNumericFactFactory)->spreadsheet($context, $native,
            NativeSpreadsheetLiteralSources::fromArtifact($context, $content));
        $binding = $publication->literalSources->binding($publication->claims[0], 13, 1);
        $base = ['fact_key' => 'material_code', 'fact_value' => 'material:'.$binding['value_hash']];
        self::assertSame($base, EvidenceSchema::value(EvidenceType::SourceFact, $base));
        self::assertArrayHasKey('native_literal_source', EvidenceSchema::value(EvidenceType::SourceFact,
            [...$base, 'native_literal_source' => $binding]));
        foreach ([1000001, PHP_INT_MAX] as $id) {
            $large = [...$binding, 'unit_id' => $id, 'organization_id' => $id, 'project_id' => $id, 'session_id' => $id, 'document_id' => $id];
            self::assertArrayHasKey('native_literal_source', EvidenceSchema::value(EvidenceType::SourceFact,
                [...$base, 'native_literal_source' => $large]));
        }
        foreach ([['schema_version' => 2], ['producer' => 'drawing_analyzer'], ['organization_id' => 0], ['unit_id' => '1'],
            ['artifact_sha256' => str_repeat('a', 64)], ['source_version' => 'stale'], ['value_hash' => 1.0],
            ['unit_id' => (float) PHP_INT_MAX * 2], ['unit_id' => (string) PHP_INT_MAX],
            ['cell' => 'C2:C3'], ['entity_key' => 'unknown entity'], ['fact_type' => 'description;DROP'], ['verified' => true]] as $change) {
            try {
                EvidenceSchema::value(EvidenceType::SourceFact, [...$base, 'native_literal_source' => [...$binding, ...$change]]);
                self::fail('Malformed binding must be rejected');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        EvidenceSchema::value(EvidenceType::Extracted, ['field_key' => 'material_code', 'field_value' => $base['fact_value'], 'native_literal_source' => $binding]);
    }

    public function test_native_looking_json_on_an_ocr_unit_does_not_enter_the_spreadsheet_issuer(): void
    {
        $content = $this->content($this->native());
        $reader = new class($content) implements DocumentUnitContentReader
        {
            public function __construct(private string $content) {}

            public function read(DocumentUnitExecutionContext $context): string
            {
                return $this->content;
            }
        };
        $ocr = $this->createMock(OcrClientInterface::class);
        $ocr->expects(self::once())->method('recognize')->willThrowException(new \RuntimeException('actual_ocr_branch'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('actual_ocr_branch');
        (new OcrDocumentUnitProcessor($reader, $ocr))->process($this->context($content, [], DocumentUnitType::RasterImage));
    }

    public function test_a_native_looking_artifact_for_a_non_xlsx_filename_does_not_mint_a_witness(): void
    {
        $content = $this->content($this->native());
        foreach (['source.xls', 'source.zip', 'source.pdf'] as $filename) {
            self::assertNull(NativeSpreadsheetLiteralSources::fromArtifact($this->context($content, filename: $filename), $content));
        }
    }

    public function test_legacy_native_artifact_is_processed_without_ocr_or_a_literal_witness(): void
    {
        $content = $this->content($this->native());
        $context = $this->context($content, legacyArtifact: true);
        self::assertNull(NativeSpreadsheetLiteralSources::fromArtifact($context, $content));
        $reader = new class($content) implements DocumentUnitContentReader
        {
            public function __construct(private string $content) {}

            public function read(DocumentUnitExecutionContext $context): string
            {
                return $this->content;
            }
        };
        $ocr = $this->createMock(OcrClientInterface::class);
        $ocr->expects(self::never())->method('recognize');
        $output = (new OcrDocumentUnitProcessor($reader, $ocr))->process($context);
        self::assertNotNull($output->publication);
        self::assertCount(1, $output->publication->claims);
        self::assertNull($output->publication->literalSources);
        self::assertFalse($output->publication->numericSources->certifies($output->publication->claims[0]));
    }

    #[DataProvider('partialModernMarkers')]
    public function test_partial_modern_metadata_cannot_downgrade_to_the_legacy_no_witness_path(array $markers): void
    {
        $content = $this->content($this->native());
        $this->expectException(DocumentUnitProcessingException::class);
        NativeSpreadsheetLiteralSources::fromArtifact($this->context($content, $markers, legacyArtifact: true), $content);
    }

    public static function partialModernMarkers(): array
    {
        return [[['artifact_kind' => 'spreadsheet_sheet']], [['artifact_schema_version' => 1]],
            [['artifact_kind' => null, 'artifact_schema_version' => null]]];
    }

    private function native(): array
    {
        return ['status' => 'available', 'sheet' => 'Оборудование',
            'header_cells' => [['address' => 'A1', 'value' => 'entity_key'], ['address' => 'B1', 'value' => 'parameter'],
                ['address' => 'C1', 'value' => 'value'], ['address' => 'D1', 'value' => 'unit']],
            'cells' => [['address' => 'A2', 'value' => 'equipment:pump', 'raw_value' => 'equipment:pump'],
                ['address' => 'B2', 'value' => 'equipment_specification', 'raw_value' => 'equipment_specification'],
                ['address' => 'C2', 'value' => 'Насос', 'raw_value' => 'Насос'], ['address' => 'D2', 'value' => '', 'raw_value' => '']]];
    }

    private function content(array $native): string
    {
        return json_encode(['schema_version' => 1, 'source_kind' => 'spreadsheet', 'text' => 'Насос', 'native_structure' => $native], JSON_THROW_ON_ERROR);
    }

    private function context(string $content, array $locator = [], DocumentUnitType $type = DocumentUnitType::SpreadsheetSheet, string $change = '',
        string $filename = 'source.xlsx', bool $legacyArtifact = false): DocumentUnitExecutionContext
    {
        $defaults = ['artifact_path' => 'org-11/source', 'artifact_sha256' => 'sha256:'.hash('sha256', $content),
            'artifact_bytes' => strlen($content), 'source_version' => 'sha256:'.str_repeat('a', 64), 'source_kind' => 'spreadsheet',
            'artifact_kind' => 'spreadsheet_sheet', 'artifact_schema_version' => 1];
        if ($legacyArtifact) {
            unset($defaults['artifact_kind'], $defaults['artifact_schema_version']);
            $defaults['content_type'] = 'application/vnd.most.spreadsheet-sheet+json';
        }

        return new DocumentUnitExecutionContext($change === 'unit' ? 2 : 1, $change === 'organization' ? 99 : 11,
            $change === 'project' ? 99 : 12, $change === 'session' ? 99 : 14, $change === 'document' ? 99 : 13, $type, 1,
            $change === 'version' ? 'sha256:'.str_repeat('b', 64) : 'sha256:'.str_repeat('a', 64), [...$defaults, ...$locator],
            'org-11/source.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename, 'claim', 1, 1, 'processing');
    }
}
