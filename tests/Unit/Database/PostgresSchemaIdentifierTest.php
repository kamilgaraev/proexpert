<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Support\Database\PostgresSchemaIdentifier;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PostgresSchemaIdentifierTest extends TestCase
{
    #[Test]
    public function configured_custom_schema_is_safely_quoted(): void
    {
        self::assertSame('"most-stage4"', PostgresSchemaIdentifier::quote('most-stage4'));
        self::assertSame('"schema""name"', PostgresSchemaIdentifier::quote('schema"name'));
    }

    #[Test]
    public function empty_or_control_character_schema_is_rejected(): void
    {
        foreach (['', "bad\0schema", "bad\nschema"] as $schema) {
            try {
                PostgresSchemaIdentifier::quote($schema);
                self::fail('Unsafe schema was accepted.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
