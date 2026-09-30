<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantSalesParentProjection;
use App\BusinessModules\Features\Procurement\Reporting\Award\Models\ProcurementAwardEvidenceEvent;
use App\Models\User;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class AssistantSalesParentProjectionTest extends TestCase
{
    use UsesAssistantUnitTranslations;

    public function test_projection_has_only_actual_native_schema_columns_and_no_composite_fake_id(): void
    {
        $schema = file_get_contents(dirname(__DIR__, 3).'/app/BusinessModules/Features/Procurement/migrations/2026_08_01_000002_create_procurement_award_source.php');
        self::assertIsString($schema);
        foreach (Metadata::parentProjectionDefinitions()['procurement_award_evidence_event'] as $definition) {
            self::assertSame(1, preg_match('/Schema::create\(\x27'.preg_quote($definition['table'], '/').'\x27.*?\n        \}\);/s', $schema, $body));
            foreach ($definition['fields'] as $field) { self::assertStringContainsString("('".$field."'", $body[0], $definition['table'].'.'.$field); }
            self::assertNotContains('id', $definition['fields']);
        }
    }

    public function test_money_strings_keep_native_precision_and_invalid_numeric_payload_is_never_a_fact(): void
    {
        $method = new ReflectionMethod(AssistantSalesParentProjection::class, 'values');
        $values = $method->invoke(null, ['total_amount' => '123456789.12345678', 'delivery_amount' => '5.00', 'comparison_total' => 'NaN', 'vat_rate' => 3.25, 'ordinal' => 8, 'payload' => ['secret']], ['total_amount', 'delivery_amount', 'comparison_total', 'vat_rate', 'ordinal']);
        self::assertSame(['total_amount' => '123456789.12345678', 'delivery_amount' => '5.00', 'ordinal' => 8], $values);
    }

    public function test_receipt_uses_real_parent_identity_native_key_and_changes_with_row_or_policy_pin(): void
    {
        $reader = (new ReflectionClass(AssistantSalesParentProjection::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(AssistantSalesParentProjection::class, 'reference');
        $parent = new ProcurementAwardEvidenceEvent;
        $parent->setRawAttributes(['id' => '11111111-1111-4111-8111-111111111111', 'organization_id' => 17, 'project_id' => 29, 'policy_id' => '22222222-2222-4222-8222-222222222222', 'policy_version' => 1, 'policy_hash' => str_repeat('a', 64)], true);
        $definition = Metadata::parentProjectionDefinitions()['procurement_award_evidence_event']['award_candidates'];
        $key = ['event_id' => $parent->getKey(), 'ordinal' => 8];
        $arguments = [17, 'procurement_award_evidence_event', $parent, 'award_candidates', $definition, $key, ['ordinal' => 8, 'total_amount' => '99.01'], '2026-09-29T10:00:00Z'];
        $reference = $method->invokeArgs($reader, $arguments);
        self::assertSame($parent->getKey(), $reference['entity_id']);
        self::assertSame($key, $reference['composite_key']);
        self::assertSame('structured', $reference['content_scope']);
        self::assertContains('finance.view', $reference['required_permissions']);
        self::assertSame(['ordinal', 'total_amount'], $reference['checked_fields']);
        $arguments[6]['total_amount'] = '99.02';
        self::assertNotSame($reference['source_version'], $method->invokeArgs($reader, $arguments)['source_version']);
        $arguments[4] = Metadata::parentProjectionDefinitions()['procurement_award_evidence_event']['award_policy'];
        $arguments[3] = 'award_policy';
        $arguments[5] = ['policy_id' => $parent->getAttribute('policy_id'), 'version' => 1];
        $arguments[6] = ['version' => 1];
        $firstPolicy = $method->invokeArgs($reader, $arguments);
        $parent->setRawAttributes(array_replace($parent->getAttributes(), ['policy_hash' => str_repeat('b', 64)]), true);
        self::assertNotSame($firstPolicy['source_version'], $method->invokeArgs($reader, $arguments)['source_version']);
        self::assertStringNotContainsString(str_repeat('b', 64), json_encode($firstPolicy, JSON_THROW_ON_ERROR));
    }

    public function test_native_composite_key_accepts_jsonb_reordering_but_rejects_extra_or_non_native_identity(): void
    {
        $method = new ReflectionMethod(AssistantSalesParentProjection::class, 'validCompositeKey');
        $definition = Metadata::parentProjectionDefinitions()['procurement_award_evidence_event']['award_candidates'];
        $id = '11111111-1111-4111-8111-111111111111';
        self::assertTrue($method->invoke(null, ['ordinal' => 2, 'event_id' => $id], $definition));
        self::assertTrue($method->invoke(null, ['event_id' => $id, 'ordinal' => 2], $definition));
        foreach ([['event_id' => $id, 'ordinal' => 2, 'foreign_id' => 3], ['ordinal' => 2], ['event_id' => 'not-a-native-uuid', 'ordinal' => 2], ['event_id' => $id, 'ordinal' => 0], ['event_id' => $id, 'ordinal' => 2.5], ['event_id' => $id, 'ordinal' => true]] as $key) {
            self::assertFalse($method->invoke(null, $key, $definition));
        }
    }

    public function test_jsonb_reordering_preserves_full_receipt_identity_and_keeps_different_children_distinct(): void
    {
        $reference = ['organization_id' => 17, 'entity_type' => 'procurement_award_evidence_event',
            'entity_id' => '11111111-1111-4111-8111-111111111111', 'projection_name' => 'award_candidates',
            'composite_key' => ['event_id' => '11111111-1111-4111-8111-111111111111', 'ordinal' => 2],
            'checked_fields' => ['ordinal', 'total_amount'], 'required_permissions' => ['procurement.supplier_proposals.view', 'finance.view']];
        $reordered = array_reverse($reference, true);
        $reordered['composite_key'] = array_reverse($reference['composite_key'], true);
        $class = \App\BusinessModules\Features\AIAssistant\Services\AssistantSourceReferenceIdentity::class;
        self::assertSame($class::key($reference), $class::key($reordered));
        $reordered['composite_key']['ordinal'] = 1;
        self::assertNotSame($class::key($reference), $class::key($reordered));
    }

    public function test_historical_financial_reference_with_revoked_current_field_permission_never_queries_rows(): void
    {
        $policy = (new ReflectionClass(\App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy::class))->newInstanceWithoutConstructor();
        $authorization = $this->createMock(\App\Domain\Authorization\Services\AuthorizationService::class);
        $authorization->method('canCurrent')->willReturn(false);
        $reader = new AssistantSalesParentProjection($policy, $authorization);
        $reference = ['organization_id' => 17, 'entity_type' => 'procurement_award_evidence_event', 'source_type' => 'procurement_business',
            'entity_id' => '11111111-1111-4111-8111-111111111111', 'content_scope' => 'structured', 'projection_name' => 'award_candidates',
            'composite_key' => ['ordinal' => 2, 'event_id' => '11111111-1111-4111-8111-111111111111'],
            'checked_fields' => ['ordinal', 'total_amount'], 'required_permissions' => ['procurement.supplier_proposals.view', 'procurement.proposal_decisions.view', 'finance.view'],
            'required_domains' => ['procurement_business'], 'fetched_at' => '2020-01-01T00:00:00Z'];
        self::assertFalse($reader->canReadReference(new User, 17, $reference));
    }

    public function test_global_policy_is_bound_to_exact_native_parent_policy_id_version_and_hash_before_limit(): void
    {
        $reader = (new ReflectionClass(AssistantSalesParentProjection::class))->newInstanceWithoutConstructor();
        $connection = new PostgresConnection(null, 'isolated_no_connection', '', ['driver' => 'pgsql']);
        app()->instance('db', $connection);
        $parent = new ProcurementAwardEvidenceEvent;
        $parent->setRawAttributes(['id' => '11111111-1111-4111-8111-111111111111', 'policy_id' => '22222222-2222-4222-8222-222222222222', 'policy_version' => 3, 'policy_hash' => str_repeat('a', 64)], true);
        $query = (new ReflectionMethod(AssistantSalesParentProjection::class, 'query'))->invoke($reader, new User, 17, $parent, Metadata::parentProjectionDefinitions()['procurement_award_evidence_event']['award_policy']);
        $sql = $query->limit(20)->toSql();
        self::assertStringContainsString('"policy_id" = ?', $sql);
        self::assertStringContainsString('"version" = ?', $sql);
        self::assertStringContainsString('"policy_hash" = ?', $sql);
        self::assertStringEndsWith('limit 20', $sql);
        self::assertSame([$parent->getAttribute('policy_id'), 3, str_repeat('a', 64)], $query->getBindings());
    }
}
