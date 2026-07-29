<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Validation;

use App\BusinessModules\Core\Reporting\Infrastructure\Validation\Draft202012SchemaValidator;
use App\BusinessModules\Core\Reporting\Infrastructure\Validation\ReportSchemaValidationException;
use Opis\JsonSchema\CompliantValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class Draft202012SchemaValidatorTest extends TestCase
{
    private const MANAGEMENT_SCHEMA_ID = 'most.management-catalog.v1';

    public function test_management_and_official_documents_are_valid_draft_2020_12(): void
    {
        $validator = $this->validator();
        $managementSchema = $this->jsonObject('management-catalog.v1.schema.json');
        $officialSchema = $this->jsonObject('official-document-catalog.v1.schema.json');

        self::assertSame(
            'urn:most:reporting:management-catalog:v1',
            $managementSchema->{'$id'},
        );
        self::assertSame(
            'urn:most:reporting:official-document-catalog:v1',
            $officialSchema->{'$id'},
        );

        self::assertTrue($validator->validate(
            $this->yamlObject('management-catalog.v1.yaml'),
            $managementSchema,
        )->isValid());
        self::assertTrue($validator->validate(
            $this->yamlObject('official-document-catalog.v1.yaml'),
            $officialSchema,
        )->isValid());
    }

    public function test_unknown_fields_and_eighth_catalog_group_fail_closed(): void
    {
        $document = $this->yamlObject('management-catalog.v1.yaml');
        $document->unexpected = true;
        self::assertFalse($this->validator()->validate($document, $this->managementSchema())->isValid());

        $document = $this->yamlObject('management-catalog.v1.yaml');
        $document->definitions[0]->unexpected = true;
        self::assertFalse($this->validator()->validate($document, $this->managementSchema())->isValid());

        $document = $this->yamlObject('management-catalog.v1.yaml');
        $document->definitions[0]->catalog_group = 'operations';
        self::assertFalse($this->validator()->validate($document, $this->managementSchema())->isValid());
    }

    public function test_candidate_definitions_require_non_empty_contract_collections(): void
    {
        foreach (['filters', 'columns', 'sorts', 'formats'] as $collection) {
            $document = $this->yamlObject('management-catalog.v1.yaml');
            $definition = $document->definitions[0];
            $definition->filters = [(object) ['id' => 'project_id']];
            $definition->columns = [(object) ['id' => 'project_name']];
            $definition->sorts = [(object) ['id' => 'project_name']];
            $definition->formats = ['xlsx'];
            $definition->readiness->publication = 'candidate';
            $definition->{$collection} = [];

            self::assertFalse(
                $this->validator()->validate($document, $this->managementSchema())->isValid(),
                $collection,
            );
        }
    }

    public function test_published_definitions_require_ready_source_formula_and_verified_delivery(): void
    {
        $invalidStates = [
            ['source', 'partial'],
            ['formula', 'contract_required'],
            ['delivery', 'not_implemented'],
        ];

        foreach ($invalidStates as [$field, $value]) {
            $document = $this->yamlObject('management-catalog.v1.yaml');
            $definition = $document->definitions[0];
            $definition->filters = [(object) ['id' => 'project_id']];
            $definition->columns = [(object) ['id' => 'project_name']];
            $definition->sorts = [(object) ['id' => 'project_name']];
            $definition->formats = ['xlsx'];
            $definition->readiness->source = 'ready';
            $definition->readiness->formula = 'ready';
            $definition->readiness->delivery = 'verified';
            $definition->readiness->publication = 'published';
            $definition->readiness->{$field} = $value;

            self::assertFalse(
                $this->validator()->validate($document, $this->managementSchema())->isValid(),
                $field,
            );
        }
    }

    public function test_assert_valid_exposes_only_allowlisted_schema_identity(): void
    {
        $document = $this->yamlObject('management-catalog.v1.yaml');
        $document->definitions[0]->catalog_group = 'operations';

        try {
            $this->validator()->assertValid(
                $document,
                $this->managementSchema(),
                self::MANAGEMENT_SCHEMA_ID,
            );
            self::fail('Invalid catalog must be rejected.');
        } catch (ReportSchemaValidationException $exception) {
            self::assertSame(self::MANAGEMENT_SCHEMA_ID, $exception->schemaId);
            self::assertSame('report_schema_invalid', $exception->getMessage());
            self::assertStringNotContainsString('operations', $exception->getMessage());
        }
    }

    private function validator(): Draft202012SchemaValidator
    {
        return new Draft202012SchemaValidator(new CompliantValidator());
    }

    private function managementSchema(): object
    {
        return $this->jsonObject('management-catalog.v1.schema.json');
    }

    private function yamlObject(string $file): object
    {
        return $this->toObject(Yaml::parseFile($this->resourcePath($file)));
    }

    private function jsonObject(string $file): object
    {
        return json_decode(
            (string) file_get_contents($this->resourcePath($file)),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function toObject(mixed $value): object
    {
        return json_decode(
            json_encode($value, JSON_THROW_ON_ERROR),
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function resourcePath(string $file): string
    {
        return dirname(__DIR__, 4).'/app/BusinessModules/Core/Reporting/resources/'.$file;
    }
}
