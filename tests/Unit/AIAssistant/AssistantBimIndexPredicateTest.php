<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexPredicate;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class AssistantBimIndexPredicateTest extends TestCase
{
    private $previousResolver;

    public function test_known_equipment_prefilter_preserves_authorized_scope_and_reads_identity_and_enum_in_one_pass(): void
    {
        $scope = $this->scope();
        $scope->where('design_ifc_model_elements.project_id', 52)
            ->where('design_ifc_model_elements.version_id', 15)->where('design_ifc_model_elements.derivative_id', 7)
            ->whereIn('design_ifc_model_elements.id', $scope->getQuery()->newQuery()->from('authorized_elements')->select('id'));
        (new AssistantBimIndexPredicate)->apply($scope, ['equipment_kind' => 'air_conditioner'], []);
        $sql = $scope->toSql();
        self::assertSame(1, substr_count($sql, 'jsonb_path_query'));
        self::assertStringContainsString('bool_or(', $sql);
        self::assertStringContainsString('assistant_equipment_identity', $sql);
        self::assertStringContainsString('where "design_ifc_model_elements"."project_id" = ?', $sql);
        self::assertStringContainsString('"design_ifc_model_elements"."version_id" = ?', $sql);
        self::assertStringContainsString('"design_ifc_model_elements"."derivative_id" = ?', $sql);
        self::assertStringContainsString('"design_ifc_model_elements"."id" in (select "id" from "authorized_elements")', $sql);
        self::assertStringContainsString('and upper(design_ifc_model_elements.category) IN (', $sql);
        self::assertStringNotContainsString("'IFCWALL'", $sql);
        self::assertSame([52, 15, 7, 'air_conditioner'], $scope->getBindings());
    }

    public function test_unknown_equipment_keeps_structural_and_missing_categories_in_the_scope(): void
    {
        $scope = $this->scope();
        (new AssistantBimIndexPredicate)->apply($scope, ['equipment_kind' => 'unknown'], []);
        self::assertStringNotContainsString('where upper(design_ifc_model_elements.category) IN (', $scope->toSql());
        self::assertSame(['unknown'], $scope->getBindings());
    }

    public function test_empty_and_null_equipment_kind_preserve_typed_pump_and_fan_word_queries(): void
    {
        $initial = $this->scope();
        foreach (['насосы' => ['pump', 'IFCPUMP'], 'вентиляторы' => ['fan', 'IFCFAN']] as $query => [$kind, $category]) {
            foreach (['', null] as $emptyKind) {
                $scope = clone $initial;
                (new AssistantBimIndexPredicate)->apply($scope, ['element_query' => $query, 'equipment_kind' => $emptyKind], []);
                $sql = $scope->toSql();
                self::assertStringContainsString(", '{$category}') offset 0", $sql);
                self::assertSame([$kind], $scope->getBindings());
            }
        }
    }
    public function test_semantic_air_conditioner_query_uses_explicit_type_evidence_and_never_the_unitary_category_alone(): void
    {
        $scope = $this->scope();
        (new AssistantBimIndexPredicate)->apply($scope, ['element_query' => 'кондиционеры'], []);
        $sql = $scope->toSql();
        self::assertStringContainsString('AIRCONDITIONINGUNIT', $sql);
        self::assertStringContainsString('{_ifc,type_predefined_type}', $sql);
        self::assertStringContainsString('jsonb_path_query', $sql);
        self::assertStringNotContainsString("category) = 'IFCUNITARYEQUIPMENT'", $sql);
        self::assertSame(['air_conditioner'], $scope->getBindings());
    }

    public function test_property_value_stays_bound_and_properties_use_closed_engineering_keys(): void
    {
        $scope = $this->scope();
        $query = "x%' OR true --";
        (new AssistantBimIndexPredicate)->apply($scope, ['property_name' => 'CoolingCapacity', 'property_value' => $query], []);
        self::assertStringNotContainsString($query, $scope->toSql());
        self::assertStringContainsString('CoolingCapacity', $scope->toSql());
        self::assertStringNotContainsString('ContactEmail', $scope->toSql());
        self::assertSame(['%coolingcapacity%', "%x\\%' or true --%"], $scope->getBindings());
    }

    public function test_category_and_morphological_name_matches_remain_alternatives_with_additional_material_filter(): void
    {
        $scope = $this->scope();
        (new AssistantBimIndexPredicate)->apply($scope, ['element_query' => 'перекрытия', 'material_query' => 'бетон'], ['IFCSLAB']);
        self::assertContains('IFCSLAB', $scope->getBindings());
        self::assertContains('%перекрыти%', $scope->getBindings());
        self::assertContains('%бетон%', $scope->getBindings());
        self::assertStringContainsString("properties -> 'materials'", $scope->toSql());
    }

    public function test_sql_classifier_uses_the_same_closed_generic_classes_and_reference_guards_as_projection(): void
    {
        $scope = $this->scope();
        (new AssistantBimIndexPredicate)->apply($scope, ['equipment_kind' => 'air_conditioner'], []);
        $sql = $scope->toSql();
        self::assertStringContainsString("NOT IN ('IFCUNITARYEQUIPMENT', 'IFCBUILDINGELEMENTPROXY', 'IFCFLOWTERMINAL'", $sql);
        self::assertStringContainsString("THEN 'unknown'", $sql);
        self::assertStringContainsString('(?:для|к|под|for|to|under)', $sql);
        self::assertStringContainsString('кронштейн|трубопровод|подставк|креплен|отверст', $sql);
        self::assertStringContainsString("p.key ~* '^PredefinedType$'", $sql);
        self::assertStringNotContainsString("'IFCDUCTSEGMENT'", $sql);
        self::assertStringNotContainsString("'IFCWALL'", $sql);
    }

    public function test_sql_vrf_identity_uses_closed_engineering_aliases_and_shared_accessory_and_room_vetoes(): void
    {
        $scope = $this->scope();
        (new AssistantBimIndexPredicate)->apply($scope, ['equipment_kind' => 'air_conditioner'], []);
        $sql = $scope->toSql();
        self::assertStringContainsString('GP_Наименование|ADSK_Наименование|Наименование', $sql);
        self::assertStringContainsString('{Прочее,Категория}', $sql);
        self::assertStringContainsString(\App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexProjection::airConditionerUnitPattern(), $sql);
        self::assertStringContainsString(\App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexProjection::airConditionerUnitReferencePattern(), $sql);
        self::assertStringContainsString('assistant_equipment_identity', $sql);
        self::assertSame(['air_conditioner'], $scope->getBindings());
    }

    private function scope(): Builder
    {
        $connection = new PostgresConnection(null);
        $this->previousResolver = Model::getConnectionResolver();
        $resolver = new ConnectionResolver(['default' => $connection]);
        $resolver->setDefaultConnection('default');
        Model::setConnectionResolver($resolver);
        return (new Builder($connection->query()))->setModel(new DesignIfcModelElement);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver !== null) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }
}
