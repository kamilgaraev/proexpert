<?php

declare(strict_types=1);

namespace Tests\Unit\ExecutiveDocumentation;

use App\BusinessModules\Features\ExecutiveDocumentation\Services\ExecutiveDocumentCoverageDeclaration;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ExecutiveDocumentCoverageDeclarationTest extends TestCase
{
    public function test_scope_normalization_preserves_exact_quantity_and_rejects_unknown_fields(): void
    {
        $validator = new ExecutiveDocumentCoverageDeclaration();
        self::assertSame(['project_id' => 1, 'quantity' => '80', 'measurement_unit_id' => 7], $validator->normalizeScope([
            'project_id' => '1', 'project_location_id' => null, 'quantity' => '80.0000', 'measurement_unit_id' => '7',
        ]));
        foreach ([['invented' => null], ['project_id' => true], ['project_id' => '1.2'], ['quantity' => '80'], ['quantity' => null, 'measurement_unit_id' => 7]] as $scope) {
            try {
                $validator->normalizeScope($scope);
                self::fail('Invalid scope accepted');
            } catch (DomainException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_coverage_contains_a_smaller_volume_only_with_matching_place_work_and_unit(): void
    {
        $coverage = ['project_id' => 1, 'completed_work_id' => 2, 'project_location_id' => 3, 'measurement_unit_id' => 7, 'quantity' => '100.0000'];
        $validator = new ExecutiveDocumentCoverageDeclaration();
        self::assertTrue($validator->covers($coverage, array_replace($coverage, ['quantity' => '80'])));
        self::assertTrue($validator->covers($coverage, array_replace($coverage, ['quantity' => '100'])));
        foreach ([['quantity' => '101'], ['project_location_id' => 4], ['completed_work_id' => 9], ['measurement_unit_id' => 8], ['quantity' => '0'], ['quantity' => 'NaN']] as $change) {
            self::assertFalse($validator->covers($coverage, array_replace($coverage, $change)));
        }
        self::assertFalse($validator->covers(['project_id' => 1], ['project_id' => 1, 'quantity' => '80', 'measurement_unit_id' => 7]));
        self::assertFalse($validator->covers($coverage, []));
    }

    public function test_empty_declaration_means_unknown_coverage(): void
    {
        self::assertSame([], (new ExecutiveDocumentCoverageDeclaration())->normalize([], '10.000000', 7));
    }

    public function test_normalizes_exact_quantity_and_unit(): void
    {
        self::assertSame(['quantity' => '2.5', 'measurement_unit_id' => 7], (new ExecutiveDocumentCoverageDeclaration())->normalize([
            'quantity' => '2.500000', 'measurement_unit_id' => 7,
        ], '10.000000', 7));
    }

    public function test_rejects_missing_pair_unknown_fields_wrong_unit_and_over_source(): void
    {
        $validator = new ExecutiveDocumentCoverageDeclaration();
        foreach ([
            ['quantity' => '1'],
            ['measurement_unit_id' => 7],
            ['quantity' => '1', 'measurement_unit_id' => 7, 'extra' => true],
            ['quantity' => '1', 'measurement_unit_id' => 8],
            ['quantity' => '11', 'measurement_unit_id' => 7],
        ] as $declaration) {
            try {
                $validator->normalize($declaration, '10', 7);
                self::fail('Invalid declaration was accepted.');
            } catch (DomainException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }

    public function test_rejects_zero_nan_rounding_and_overflow_values(): void
    {
        $validator = new ExecutiveDocumentCoverageDeclaration();
        foreach (['0', 'NaN', '1.0000001', '9999999999999999999'] as $quantity) {
            try {
                $validator->normalize(['quantity' => $quantity, 'measurement_unit_id' => 7], '10', 7);
                self::fail('Invalid quantity was accepted.');
            } catch (DomainException $exception) {
                self::assertSame(422, $exception->getCode());
            }
        }
    }
}
