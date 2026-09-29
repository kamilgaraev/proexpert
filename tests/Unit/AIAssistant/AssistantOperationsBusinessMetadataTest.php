<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\DomainMetadata\AssistantOperationsBusinessMetadata as Metadata;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagSourceCollectorInterface;
use PHPUnit\Framework\TestCase;

final class AssistantOperationsBusinessMetadataTest extends TestCase
{
    public function test_finite_inventory_covers_every_new_operation_model_and_classifies_exclusions(): void
    {
        $expected = explode(' ', 'asset_custody_events asset_operation_profiles organization_assets asset_categories auto_reorder_rules inventory_act_items organization_warehouses project_material_delivery_events warehouse_identifiers warehouse_item_galleries warehouse_logistic_units warehouse_scan_events warehouse_zones inventory_demand_snapshots inventory_reorder_policy_versions inventory_risk_rows inventory_risk_snapshots warehouse_daily_balance_rows warehouse_daily_balance_snapshots warehouse_inventory_events asset_requests asset_request_events machinery_defects machinery_idempotency_records machinery_shift_inspections maintenance_inspections quality_defect_photos quality_defect_status_history quality_defect_flow_events quality_defect_flow_gaps quality_defect_flow_policies quality_defect_flow_policy_versions quality_defect_flow_rows quality_defect_flow_snapshots quality_defect_transition_events safety_briefing_participants safety_employee_requirements safety_inspection_items safety_inspection_templates safety_medical_exams safety_ppe_issues safety_ppe_norms safety_requirement_matrices safety_training_records safety_work_permit_participants safety_admission_policy_versions safety_admission_rows safety_admission_snapshots safety_site_workforce_assignments safety_exposure_days safety_incident_policy_versions safety_incident_rows safety_incident_snapshots safety_sites safety_transition_events daily_work_plans daily_work_plan_assignments lookahead_plans lookahead_plan_tasks project_events schedule_baseline_versions work_constraints lookahead_reporting_rows lookahead_reporting_snapshots lookahead_reporting_constraint_transition_events baseline_schedule_variance_snapshots baseline_schedule_variance_rows schedule_task_state_versions site_requests site_request_calendar_events site_request_groups site_request_history site_request_statuses site_request_status_transitions site_request_templates');
        $actual = [...array_column(Metadata::recordDefinitions(), 'table'), ...array_keys(Metadata::excludedInventory())];
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertCount(70, Metadata::recordDefinitions());
        self::assertSame([], array_intersect(array_column(Metadata::recordDefinitions(), 'table'), array_keys(Metadata::excludedInventory())));
        foreach (Metadata::recordDefinitions() as $record) {
            $model = new $record['model'];
            self::assertSame($record['table'], $model->getTable(), $record['model']);
            self::assertSame($record['key'], $model->getKeyName(), $record['model']);
            self::assertContains($record['key'], $record['read_fields']);
            self::assertNotEmpty($record['read_fields']);
            self::assertNotEmpty($record['rag_fields']);
            foreach ($record['read_fields'] as $field) {
                self::assertArrayHasKey($field, $record['column_types']);
                self::assertNotContains($record['column_types'][$field], ['json', 'jsonb']);
            }
        }
    }

    public function test_identifier_polymorphic_targets_have_finite_current_tenant_paths_and_cell_zone_is_correlated(): void
    {
        $targets = Metadata::identifierTargets();
        self::assertSame(['warehouse', 'zone', 'cell', 'asset', 'inventory_act', 'movement', 'logistic_unit'], array_keys($targets));
        $scopes = Metadata::sourceScopeRecords();
        foreach ($targets as $type) {
            self::assertArrayHasKey($type, $scopes);
            $scope = $scopes[$type];
            self::assertTrue($scope['organization_column'] !== null || count(array_filter($scope['parents'], static fn (array $parent): bool => ! $parent['nullable'])) > 0);
        }
        self::assertSame(['warehouse_id' => 'warehouse_id'], $scopes['warehouse_storage_cell']['parents']['zone_id']['matches']);
        self::assertSame('inventory_act', $scopes['inventory_act_item']['parents']['inventory_act_id']['type']);
        self::assertFalse($scopes['inventory_act']['parents']['warehouse_id']['nullable']);
        self::assertTrue($scopes['inventory_act_item']['parents']['cell_id']['nullable']);
        self::assertFalse($scopes['inventory_act_item']['parents']['inventory_act_id']['nullable']);
        self::assertFalse($scopes['inventory_act_item']['parents']['material_id']['nullable']);
    }

    public function test_readonly_operations_have_closed_schemas_and_actual_bounded_routes(): void
    {
        $routes = ['/warehouse', '/machinery-operations', '/quality-control/defects', '/safety-management', '/schedules/planning', '/site-requests', '/site-requests/calendar', '/site-requests/templates'];
        foreach (Metadata::domainDefinitions() as $definition) {
            self::assertSame(['search', 'read', 'navigation'], $definition->operations);
            self::assertContains($definition->navigation, $routes);
            foreach ($definition->operations as $operation) {
                $schema = $definition->schema($operation);
                self::assertFalse($schema['additionalProperties']);
                self::assertSame(array_keys($schema['properties']), $schema['required']);
                self::assertSame($definition->entityTypes, $schema['properties']['entity_type']['enum']);
            }
            self::assertSame(20, $definition->schema('search')['properties']['limit']['maximum']);
            self::assertSame(['integer', 'string'], array_column($definition->schema('read')['properties']['id']['anyOf'], 'type'));
        }
        self::assertSame('event_id', Metadata::recordDefinitions()['quality_defect_flow_event']['key']);
        self::assertSame('gap_id', Metadata::recordDefinitions()['quality_defect_flow_gap']['key']);
    }

    public function test_all_fields_have_russian_labels_and_money_never_enters_rag_text(): void
    {
        $fields = Metadata::fieldLabels();
        foreach (Metadata::recordDefinitions() as $type => $record) {
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', Metadata::entityLabels()[$type]);
            foreach ($record['read_fields'] as $field) {
                self::assertArrayHasKey($field, $fields);
                self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $fields[$field]);
                self::assertStringNotContainsString('_', $fields[$field]);
                self::assertContains($field, Metadata::structuredFields());
            }
            self::assertSame([], array_intersect($record['rag_fields'], Metadata::monetaryFields()));
            self::assertSame([], array_intersect($record['read_fields'], ['metadata', 'restrictions', 'canonical_policy', 'medical_details', 'idempotency_key', 'idempotency_hash', 'url', 'source_refs', 'payload']));
        }
        foreach (Metadata::domainDefinitions() as $definition) {
            foreach (array_intersect($definition->fields, Metadata::monetaryFields()) as $money) {
                self::assertSame(['finance.view'], $definition->fieldPermissions[$money]);
                self::assertContains($money, Metadata::factFieldGroups()['money']);
                self::assertNotContains($money, Metadata::factFieldGroups()['quantity']);
            }
        }
        foreach (['safety_medical_exam', 'safety_admission_row', 'safety_admission_snapshot'] as $type) {
            self::assertSame('operations_safety_medical', Metadata::recordDefinitions()[$type]['source']);
            self::assertContains('safety-management.view', Metadata::entityPermissions()[$type]);
            if ($type === 'safety_admission_row') { self::assertContains('safety-management.medical.view', Metadata::entityPermissions()[$type]); }
            else { self::assertNotContains('safety-management.medical.view', Metadata::entityPermissions()[$type]); }
        }
        self::assertSame(['safety-management.view'], Metadata::sourcePermissions()['operations_safety_medical']);
        self::assertArrayNotHasKey('employee_id', Metadata::parentColumns()['safety_medical_exam']);
    }

    public function test_parent_security_graph_is_finite_and_only_safe_self_references_can_be_nonrecursive(): void
    {
        $records = Metadata::sourceScopeRecords();
        $visit = function (string $type, array $path) use (&$visit, $records): void {
            self::assertArrayHasKey($type, $records);
            self::assertNotContains($type, $path, implode(' -> ', [...$path, $type]));
            foreach ($records[$type]['parents'] as $column => $parent) {
                self::assertIsBool($parent['nullable']);
                if (($parent['reference_only'] ?? false) === true) {
                    self::assertSame($type, $parent['type']);
                    self::assertNotNull($records[$type]['organization_column']);
                    if (isset(Metadata::recordDefinitions()[$type])) {
                        self::assertNotSame([], array_diff(array_keys($records[$type]['parents']), [$column]));
                    }
                    continue;
                }
                $visit($parent['type'], [...$path, $type]);
            }
        };
        foreach (array_keys(Metadata::recordDefinitions()) as $type) { $visit($type, []); }
        foreach (Metadata::recordDefinitions() as $record) {
            if ($record['organization_column'] === null) {
                self::assertNotSame([], array_filter($record['parents'], static fn (array $parent): bool => ! $parent['nullable']));
            }
        }
    }

    public function test_each_enabled_source_covers_its_entire_declared_entity_set(): void
    {
        $actual = [];
        foreach (Metadata::sourceClasses() as $class) {
            $source = new $class;
            self::assertInstanceOf(RagSourceCollectorInterface::class, $source);
            self::assertTrue($source->enabled());
            foreach ($source->entities() as $type => $record) {
                self::assertSame($source->sourceType(), $record['source']);
                self::assertArrayNotHasKey($type, $actual);
                $actual[$type] = true;
            }
        }
        $expected = array_keys(Metadata::recordDefinitions());
        $actual = array_keys($actual);
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
    }
}
