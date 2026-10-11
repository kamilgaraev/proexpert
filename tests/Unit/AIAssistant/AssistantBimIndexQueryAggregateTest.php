<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexPredicate;
use App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexProjection;
use App\BusinessModules\Features\AIAssistant\Services\AssistantBimIndexQuery;
use App\BusinessModules\Features\DesignManagement\Models\DesignIfcModelElement;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class AssistantBimIndexQueryAggregateTest extends TestCase
{
    public function test_aggregates_clear_engineering_columns_and_orders_while_retaining_authorized_ids(): void
    {
        $previousResolver = Model::getConnectionResolver();
        $connection = new class(null) extends PostgresConnection
        {
            public array $statements = [];

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                $this->statements[] = $query;
                if (str_contains($query, 'aggregate')) {
                    return [(object) ['aggregate' => 0]];
                }
                if (str_contains($query, 'AS fingerprint')) {
                    return [(object) ['fingerprint' => 'empty-index']];
                }
                return [];
            }
        };
        $resolver = new ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
        try {
            $scope = (new Builder($connection->query()))->setModel(new DesignIfcModelElement)
                ->select(['design_ifc_model_elements.id', 'design_ifc_model_elements.properties', 'design_ifc_model_elements.classifications'])
                ->where('design_ifc_model_elements.organization_id', 1)
                ->whereIn('design_ifc_model_elements.id', $connection->query()->from('authorized_bim_elements')->select('id'))
                ->orderBy('design_ifc_model_elements.name');
            $result = (new AssistantBimIndexQuery(new AssistantBimIndexProjection, new AssistantBimIndexPredicate))->read($scope,
                ['offset' => 0, 'limit' => 20, 'include_properties' => false, 'include_quantities' => false], [], []);
            self::assertSame(0, $result['total']);
            self::assertSame('empty-index', $result['index_fingerprint']);
            $fingerprint = array_values(array_filter($connection->statements, static fn (string $sql): bool => str_contains($sql, 'AS fingerprint')))[0];
            self::assertStringStartsWith('select md5(', $fingerprint);
            self::assertStringNotContainsString('order by "design_ifc_model_elements"."name"', $fingerprint);
            self::assertStringContainsString('"design_ifc_model_elements"."organization_id" = ?', $fingerprint);
            self::assertStringContainsString('"design_ifc_model_elements"."id" in (select "id" from "authorized_bim_elements")', $fingerprint);
            $categories = array_values(array_filter($connection->statements, static fn (string $sql): bool => str_contains($sql, 'element_count')))[0];
            self::assertStringStartsWith('select "design_ifc_model_elements"."category", COUNT(*) AS element_count', $categories);
            self::assertStringNotContainsString('order by "design_ifc_model_elements"."name"', $categories);
        } finally {
            if ($previousResolver !== null) { Model::setConnectionResolver($previousResolver); }
            else { Model::unsetConnectionResolver(); }
        }
    }
}
