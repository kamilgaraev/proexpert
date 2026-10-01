<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use Illuminate\Database\QueryException;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

final class AssistantQueryExceptionLoggingMetadataTest extends TestCase
{
    public function test_metadata_contains_only_safe_query_diagnostics(): void
    {
        $sql = 'select * from estimate_positions where label = ?';
        $binding = 'private@example.test';
        $driverMessage = 'ERROR: column "resource_amount" does not exist';
        $previous = new PDOException($driverMessage);
        $previous->errorInfo = ['42703', 7, $driverMessage];
        $exception = new QueryException('pgsql', $sql, [$binding], $previous);

        $metadata = $this->metadata($exception);
        $serialized = serialize($metadata);

        self::assertSame([
            'sql_fingerprint' => hash('sha256', $sql),
            'sqlstate' => '42703',
            'database_identifier' => 'resource_amount',
        ], $metadata);
        self::assertStringNotContainsString($sql, $serialized);
        self::assertStringNotContainsString($binding, $serialized);
        self::assertStringNotContainsString($driverMessage, $serialized);
        self::assertStringNotContainsString('SQL:', $serialized);
    }

    public function test_identifier_is_added_only_for_a_matching_postgresql_error(): void
    {
        $driverMessage = 'ERROR: relation "estimate_positions" does not exist';
        $wrongState = $this->exceptionWithDriverMessage('42703', $driverMessage);
        $knownRelation = $this->exceptionWithDriverMessage(
            '42P01',
            'ERROR: relation "estimate_positions" does not exist'
        );
        $unsafeIdentifier = $this->exceptionWithDriverMessage(
            '42703',
            'ERROR: column "user@example.test" does not exist'
        );

        self::assertSame('estimate_positions', $this->metadata($knownRelation)['database_identifier']);
        self::assertArrayNotHasKey('database_identifier', $this->metadata($wrongState));
        self::assertArrayNotHasKey('database_identifier', $this->metadata($unsafeIdentifier));
    }

    public function test_sqlstate_falls_back_to_exception_code(): void
    {
        $exception = new QueryException('pgsql', 'select 1', [], new RuntimeException('', 42703));

        self::assertSame('42703', $this->metadata($exception)['sqlstate']);
    }

    private function exceptionWithDriverMessage(string $sqlState, string $driverMessage): QueryException
    {
        $previous = new PDOException($driverMessage);
        $previous->errorInfo = [$sqlState, 7, $driverMessage];

        return new QueryException('pgsql', 'select * from estimates', [], $previous);
    }

    private function metadata(QueryException $exception): array
    {
        $service = (new ReflectionClass(AIAssistantService::class))->newInstanceWithoutConstructor();

        return (new ReflectionClass(AIAssistantService::class))
            ->getMethod('queryExceptionDiagnosticMetadata')
            ->invoke($service, $exception);
    }
}
