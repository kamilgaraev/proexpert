<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Contracts;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportOutputClassification;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewRef;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportDataClassification;
use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotClassification;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\Sha256Hash;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Tests\Support\Reporting\ReportDefinitionBuilder;

final class ReportDefinitionContractTest extends TestCase
{
    public function test_definition_and_output_classification_have_exact_typed_surfaces(): void
    {
        $definition = new ReflectionClass(\App\BusinessModules\Core\Reporting\Domain\DTO\ReportDefinition::class);
        self::assertFalse($definition->isInterface());
        self::assertSame([
            ['code', 'string', false, null, false, false],
            ['definitionHash', Sha256Hash::class, false, null, false, false],
            ['contractVersion', 'string', false, null, false, false],
            ['formulaVersion', 'string', false, null, false, false],
            ['sourceSchemaVersion', 'string', false, null, false, false],
            ['rendererVersion', 'string', false, null, false, false],
            ['filters', 'array', false, null, false, false],
            ['columns', 'array', false, null, false, false],
            ['sorts', 'array', false, null, false, false],
            ['formats', 'array', false, null, false, false],
            ['permissionPolicy', \App\BusinessModules\Core\Reporting\Domain\DTO\ReportPermissionPolicy::class, false, null, false, false],
            ['snapshotClassification', ReportSnapshotClassification::class, false, null, false, false],
            ['outputClassification', ReportOutputClassification::class, false, null, false, false],
            ['publicationReadiness', \App\BusinessModules\Core\Reporting\Domain\Enums\ReportPublicationReadiness::class, false, null, false, false],
            ['supportsSubscriptions', 'bool', false, null, false, false],
        ], self::parameters($definition->getConstructor()?->getParameters() ?? []));
        self::assertSame([
            '__construct' => ['static' => false, 'return' => ''],
            'validatedSelectedColumnIds' => [
                'parameters' => [['columnIds', 'array', false, null, false, false]],
                'static' => false,
                'return' => 'array',
            ],
        ], self::publicMethodSignatures($definition));

        $classification = new ReflectionClass(ReportOutputClassification::class);
        self::assertFalse($classification->isInterface());
        self::assertSame([
            ['defaultClassification', ReportDataClassification::class, false, null, false, false],
            ['sensitiveColumnIds', 'array', false, null, false, false],
            ['auditColumnIds', 'array', false, null, false, false],
            ['totalsSensitive', 'bool', false, null, false, false],
            ['totalsAudit', 'bool', false, null, false, false],
            ['provenanceAudit', 'bool', false, null, false, false],
        ], self::parameters($classification->getConstructor()?->getParameters() ?? []));
        self::assertSame([
            '__construct' => ['static' => false, 'return' => ''],
            'requiresSensitiveForRows' => ['parameters' => [], 'static' => false, 'return' => 'bool'],
            'requiresAuditForRows' => ['parameters' => [], 'static' => false, 'return' => 'bool'],
            'requiresSensitiveForColumns' => [
                'parameters' => [['columnIds', 'array', false, null, false, false]],
                'static' => false,
                'return' => 'bool',
            ],
            'requiresAuditForColumns' => [
                'parameters' => [['columnIds', 'array', false, null, false, false]],
                'static' => false,
                'return' => 'bool',
            ],
            'requiresSensitiveForSummary' => ['parameters' => [], 'static' => false, 'return' => 'bool'],
            'requiresAuditForSummary' => ['parameters' => [], 'static' => false, 'return' => 'bool'],
        ], self::publicMethodSignatures($classification));
    }

    public function test_output_classification_enforces_the_complete_truth_table(): void
    {
        $standard = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            ['cost'],
            ['employee'],
            false,
            false,
            false,
        );

        self::assertFalse($standard->requiresSensitiveForRows());
        self::assertFalse($standard->requiresAuditForRows());
        self::assertTrue($standard->requiresSensitiveForColumns(['cost']));
        self::assertFalse($standard->requiresSensitiveForColumns(['name']));
        self::assertTrue($standard->requiresAuditForColumns(['employee']));
        self::assertFalse($standard->requiresAuditForColumns(['name']));
        self::assertFalse($standard->requiresSensitiveForSummary());
        self::assertFalse($standard->requiresAuditForSummary());

        $sensitive = new ReportOutputClassification(
            ReportDataClassification::SENSITIVE,
            [],
            [],
            false,
            false,
            true,
        );

        self::assertTrue($sensitive->requiresSensitiveForRows());
        self::assertFalse($sensitive->requiresAuditForRows());
        self::assertTrue($sensitive->requiresSensitiveForColumns(['name']));
        self::assertFalse($sensitive->requiresAuditForColumns(['name']));
        self::assertTrue($sensitive->requiresSensitiveForSummary());
        self::assertTrue($sensitive->requiresAuditForSummary());

        $totals = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            [],
            [],
            true,
            true,
            false,
        );

        self::assertTrue($totals->requiresSensitiveForRows());
        self::assertTrue($totals->requiresAuditForRows());
        self::assertTrue($totals->requiresSensitiveForSummary());
        self::assertTrue($totals->requiresAuditForSummary());
    }

    public function test_classified_ids_are_sorted_and_must_belong_to_definition_columns(): void
    {
        $classification = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            ['cost', 'amount'],
            ['employee', 'amount'],
            false,
            false,
            false,
        );

        self::assertSame(['amount', 'cost'], $classification->sensitiveColumnIds);
        self::assertSame(['amount', 'employee'], $classification->auditColumnIds);

        $definition = (new ReportDefinitionBuilder())
            ->columns([['id' => 'amount'], ['id' => 'cost'], ['id' => 'employee']])
            ->outputClassification($classification)
            ->payload();

        self::assertSame($classification, $definition->outputClassification);
        self::assertSame(ReportSnapshotClassification::OPERATIONAL, $definition->snapshotClassification);
    }

    #[DataProvider('invalidClassificationIds')]
    public function test_output_classification_rejects_invalid_or_duplicate_ids(array $sensitive, array $audit): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            $sensitive,
            $audit,
            false,
            false,
            false,
        );
    }

    public static function invalidClassificationIds(): array
    {
        return [
            'not a list' => [['column' => 'cost'], []],
            'invalid grammar' => [['Cost'], []],
            'duplicate sensitive' => [['cost', 'cost'], []],
            'duplicate audit' => [[], ['audit', 'audit']],
            'non-string' => [[1], []],
        ];
    }

    public function test_definition_rejects_classified_column_absent_from_columns(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportDefinitionBuilder())
            ->outputClassification(new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                ['secret'],
                [],
                false,
                false,
                false,
            ))
            ->payload();
    }

    #[DataProvider('invalidSelectedColumns')]
    public function test_selected_columns_must_be_valid_and_unique(array $selected): void
    {
        $classification = new ReportOutputClassification(
            ReportDataClassification::STANDARD,
            ['cost'],
            [],
            false,
            false,
            false,
        );
        $this->expectException(InvalidArgumentException::class);

        $classification->requiresSensitiveForColumns($selected);
    }

    public static function invalidSelectedColumns(): array
    {
        return [
            'duplicate' => [['cost', 'cost']],
            'invalid grammar' => [['Cost']],
            'not a list' => [['column' => 'cost']],
        ];
    }

    public function test_definition_validates_selected_membership_before_classification_decision(): void
    {
        $definition = (new ReportDefinitionBuilder())
            ->columns([['id' => 'name'], ['id' => 'cost']])
            ->outputClassification(new ReportOutputClassification(
                ReportDataClassification::STANDARD,
                ['cost'],
                [],
                false,
                false,
                false,
            ))
            ->payload();

        self::assertSame(['cost', 'name'], $definition->validatedSelectedColumnIds(['name', 'cost']));
        self::assertTrue($definition->outputClassification->requiresSensitiveForColumns(
            $definition->validatedSelectedColumnIds(['cost']),
        ));

        $this->expectException(InvalidArgumentException::class);
        $definition->validatedSelectedColumnIds(['unknown']);
    }

    public function test_saved_view_reference_requires_ulid_positive_revision_and_hash(): void
    {
        $reference = new ReportSavedViewRef(
            '01J00000000000000000000000',
            2,
            new Sha256Hash(str_repeat('a', 64)),
        );

        self::assertSame(2, $reference->revision);
    }

    #[DataProvider('invalidSavedViewReferences')]
    public function test_saved_view_reference_rejects_invalid_identity(string $id, int $revision): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReportSavedViewRef($id, $revision, new Sha256Hash(str_repeat('a', 64)));
    }

    public static function invalidSavedViewReferences(): array
    {
        return [
            'invalid ulid' => ['bad', 1],
            'lowercase ulid' => ['01j00000000000000000000000', 1],
            'overflow ulid' => ['81J00000000000000000000000', 1],
            'zero revision' => ['01J00000000000000000000000', 0],
        ];
    }

    private static function publicMethodSignatures(ReflectionClass $class): array
    {
        $signatures = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }

            $signature = [
                'static' => $method->isStatic(),
                'return' => (string) $method->getReturnType(),
            ];
            if (!$method->isConstructor()) {
                $signature = ['parameters' => self::parameters($method->getParameters())] + $signature;
            }
            $signatures[$method->getName()] = $signature;
        }

        return $signatures;
    }

    private static function parameters(array $parameters): array
    {
        return array_map(
            static fn (ReflectionParameter $parameter): array => [
                $parameter->getName(),
                (string) $parameter->getType(),
                $parameter->isDefaultValueAvailable(),
                $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                $parameter->isPassedByReference(),
                $parameter->isVariadic(),
            ],
            $parameters,
        );
    }
}
