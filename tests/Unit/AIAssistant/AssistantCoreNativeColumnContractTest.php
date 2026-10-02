<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantCoreBusinessMetadata as Metadata;
use App\Models\ContractCurrentState;
use App\Models\Supplier;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class AssistantCoreNativeColumnContractTest extends TestCase
{
    public function test_contract_state_uses_real_contract_primary_key_in_current_read_and_index_identity(): void
    {
        $record = Metadata::inventory()['core_contract_current_state'];
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2025_10_30_034205_create_contract_current_state_table.php');
        self::assertIsString($migration);
        self::assertStringContainsString("foreignId('contract_id')->primary()", $migration);
        $state = new ContractCurrentState;
        $state->forceFill(['contract_id' => 29, 'current_total_amount' => '123.45']);
        self::assertSame('contract_id', $state->getKeyName());
        foreach (['fields','read_fields','rag_fields'] as $list) {
            self::assertContains('contract_id', $record[$list]);
            self::assertNotContains('id', $record[$list]);
        }
        self::assertSame('id', $record['parents']['contract_id']['key']);
        self::assertTrue($record['indexed']);
        self::assertSame(29, $state->getKey());
        self::assertSame('contract_current_state.contract_id', $state->getQualifiedKeyName());
    }

    public function test_supplier_selects_actual_tax_number_without_claiming_inn_or_ogrn_aliases(): void
    {
        $record = Metadata::inventory()['core_supplier'];
        $migration = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2025_01_01_000060_create_suppliers_table.php');
        self::assertIsString($migration);
        self::assertStringContainsString("string('tax_number')", $migration);
        self::assertStringNotContainsString("string('inn')", $migration);
        self::assertStringNotContainsString("string('ogrn')", $migration);
        self::assertSame('Налоговый номер', Metadata::fieldLabels()['tax_number']);
        foreach (['fields','read_fields','rag_fields'] as $list) {
            self::assertContains('tax_number', $record[$list]);
            self::assertNotContains('inn', $record[$list]);
            self::assertNotContains('ogrn', $record[$list]);
        }
        $previous = Model::getConnectionResolver();
        $resolver = $this->createMock(ConnectionResolverInterface::class);
        $resolver->method('connection')->willReturn(new PostgresConnection(static fn () => throw new \LogicException('Column contract test must not connect to PostgreSQL')));
        Model::setConnectionResolver($resolver);
        try {
            $query = Supplier::query()->select(Metadata::safeSelectColumns()['core_supplier']);
            self::assertStringContainsString('"tax_number"', $query->toSql());
            self::assertStringNotContainsString('"inn"', $query->toSql());
            self::assertStringNotContainsString('"ogrn"', $query->toSql());
        } finally {
            $previous === null ? Model::unsetConnectionResolver() : Model::setConnectionResolver($previous);
        }
    }
}
