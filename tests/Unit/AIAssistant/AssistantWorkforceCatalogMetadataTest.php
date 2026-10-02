<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\ReadAdapters\AssistantWorkforceStaffUnitRecord;
use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantWorkforceCatalogMetadata as Metadata;
use LogicException;
use PHPUnit\Framework\TestCase;

final class AssistantWorkforceCatalogMetadataTest extends TestCase
{
    public function test_inventory_classifies_every_workforce_table_and_has_closed_readonly_schemas(): void
    {
        $tables = [];
        $root = dirname(__DIR__, 3).'/app/BusinessModules/Features/WorkforceManagement/migrations';
        foreach (glob($root.'/*.php') as $path) {
            preg_match_all("/Schema::create\('([^']+)'/", file_get_contents($path), $matches);
            $tables = [...$tables, ...$matches[1]];
        }
        $included = array_column(Metadata::recordDefinitions(), 'table');
        $excluded = array_keys(Metadata::excludedInventory());
        $this->assertSame([], array_values(array_diff($tables, [...$included, ...$excluded])));
        $this->assertSame([], array_values(array_intersect($included, $excluded)));
        foreach (Metadata::domainDefinitions() as $definition) {
            foreach (['search', 'read', 'navigation'] as $operation) {
                $schema = $definition->schema($operation);
                $this->assertFalse($schema['additionalProperties']);
                $this->assertSame(array_keys($schema['properties']), $schema['required']);
                $this->assertSame($definition->entityTypes, $schema['properties']['entity_type']['enum']);
            }
            $this->assertNotContains('delete', $definition->operations);
        }
    }

    public function test_every_readable_field_and_entity_has_a_user_label_and_explicit_fact_support(): void
    {
        $labels = Metadata::fieldLabels();
        $entityLabels = Metadata::entityLabels();
        $structured = Metadata::structuredFields();
        foreach (Metadata::recordDefinitions() as $type => $record) {
            $this->assertArrayHasKey($type, $entityLabels);
            foreach ($record['read_fields'] as $field) {
                $this->assertArrayHasKey($field, $labels);
                $this->assertContains($field, $structured);
            }
        }
        $this->assertContains('base_salary', Metadata::factFieldGroups()['money']);
        $this->assertNotContains('rate', Metadata::factFieldGroups()['money']);
        $this->assertContains('rate', Metadata::factFieldGroups()['quantity']);
        $this->assertContains('employment_status', Metadata::factFieldGroups()['status']);
    }

    public function test_baseline_index_never_serializes_salary_contacts_credentials_or_file_storage_paths(): void
    {
        $forbidden = ['base_salary', 'amount', 'gross_amount', 'default_price', 'phone', 'email', 'contact_phone', 'contact_email',
            'external_payroll_ref', 'metadata', 'payload', 'source_canonical', 'content_canonical', 'policy_definition', 'token_hash', 'storage_path', 'file_path'];
        foreach (Metadata::recordDefinitions() as $type => $record) {
            $this->assertSame([], array_values(array_intersect($forbidden, $record['rag_fields'])), $type);
            $this->assertNotEmpty($record['permissions'], $type);
            $this->assertContains('id', Metadata::safeSelectColumns()[$type]);
            $this->assertTrue(class_exists($record['model']), $type);
            $this->assertSame($record['table'], (new $record['model'])->getTable());
        }
        $domains = [];
        foreach (Metadata::domainDefinitions() as $definition) { $domains[$definition->domain] = $definition; }
        $this->assertSame(['workforce.payroll-source.manage', 'finance.view'], $domains['workforce']->fieldPermissions['base_salary']);
        $this->assertSame('workforce.hr.manage', $domains['workforce']->fieldPermissions['phone']);
        $this->assertSame(['workforce.view', 'workforce.payroll-source.manage', 'finance.view'], $domains['workforce_payroll']->permissions);
        $this->assertSame('finance.view', $domains['materials']->fieldPermissions['default_price']);
        $this->assertSame('brigades.catalog.moderate', $domains['brigades']->fieldPermissions['contact_phone']);
    }

    public function test_parent_lineage_and_nonstandard_organization_columns_are_declared(): void
    {
        $this->assertSame(['type' => 'brigade_profile', 'nullable' => false], Metadata::parentColumns()['brigade_document']['brigade_id']);
        $this->assertSame('contractor_organization_id', Metadata::organizationColumns()['brigade_assignment']);
        $this->assertSame(['type' => 'workforce_payroll_period', 'nullable' => false], Metadata::parentColumns()['workforce_export_package']['payroll_period_id']);
        $this->assertTrue(Metadata::organizationAggregates()['workforce_payroll_statement']);
        $this->assertTrue(Metadata::globalCatalogEntities()['brigade_specialization']);
        $this->assertSame('approved', Metadata::publicCatalogEntities()['brigade_profile']['approved_value']);
        $this->assertSame('credential_token', Metadata::excludedInventory()['workforce_attendance_qr_tokens']);
    }

    public function test_raw_table_adapter_cannot_save_even_force_filled_salary(): void
    {
        $model = new AssistantWorkforceStaffUnitRecord();
        $model->forceFill(['base_salary' => '99999999.99']);
        $this->assertSame('decimal:2', $model->getCasts()['base_salary']);
        $this->expectException(LogicException::class);
        $model->save();
    }

    public function test_security_parent_graph_has_only_explicit_reference_only_self_edges(): void
    {
        $records = Metadata::recordDefinitions();
        $seen = [];
        $active = [];
        $references = [];
        $visit = function (string $type) use (&$visit, &$seen, &$active, &$references, $records): void {
            $this->assertArrayNotHasKey($type, $active, 'Security parent cycle at '.$type);
            if (isset($seen[$type])) { return; }
            $active[$type] = true;
            foreach ($records[$type]['parents'] as $column => $parent) {
                $this->assertArrayHasKey($parent['type'], $records);
                if (($parent['reference_only'] ?? false) === true) {
                    $this->assertSame($type, $parent['type'], 'Cross-type security lineage cannot be a reference-only edge.');
                    $references[] = $type.'.'.$column;
                    continue;
                }
                $visit($parent['type']);
            }
            unset($active[$type]);
            $seen[$type] = true;
        };
        foreach (array_keys($records) as $type) { $visit($type); }
        $this->assertEqualsCanonicalizing(['workforce_department.parent_id', 'workforce_export_package.supersedes_package_id'], $references);
        $this->assertFalse($records['workforce_export_package']['parents']['payroll_period_id']['reference_only'] ?? false);
    }

    public function test_navigation_targets_existing_catalog_and_brigade_sections(): void
    {
        $routes = Metadata::navigationTemplates();
        $this->assertSame('/catalogs/materials', $routes['material']);
        $this->assertSame('/catalogs/work-types', $routes['work_type']);
        $this->assertSame('/catalogs/measurement-units', $routes['measurement_unit']);
        $this->assertSame('/brigades/catalog', $routes['brigade_assignment']);
        $this->assertSame('/brigades/requests', $routes['brigade_request']);
        $this->assertSame('/brigades/invitations', $routes['brigade_invitation']);
        foreach (Metadata::domainDefinitions() as $definition) {
            $this->assertSame($routes[$definition->entityType], $definition->navigation);
        }
    }
}
