<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Database;

use App\BusinessModules\Addons\EstimateGeneration\Database\PostgresCheckConstraintDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresCheckConstraintDefinitionTest extends TestCase
{
    #[Test]
    public function unary_not_group_cannot_collide_with_a_distinct_boolean_expression(): void
    {
        $expected = PostgresCheckConstraintDefinition::canonical(
            'CHECK ((is_current AND invalidated_at IS NULL) OR (NOT is_current AND invalidated_at IS NOT NULL))',
        );
        $weakened = PostgresCheckConstraintDefinition::canonical(
            'CHECK ((is_current AND invalidated_at IS NULL) OR NOT (is_current AND invalidated_at IS NOT NULL))',
        );

        self::assertNotSame($expected, $weakened);
    }

    #[Test]
    public function redundant_boolean_groups_still_normalize_across_postgres_deparse_shapes(): void
    {
        self::assertSame(
            PostgresCheckConstraintDefinition::canonical('CHECK (a AND b OR c AND d)'),
            PostgresCheckConstraintDefinition::canonical('CHECK ((a AND b) OR (c AND d))'),
        );
    }
}
