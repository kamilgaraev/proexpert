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
