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
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;

final class AssistantBimIndexQueryAggregateTest extends TestCase
{
    public function test_equipment_total_and_categories_share_their_scoped_aggregates(): void
    {
        $previousResolver = Model::getConnectionResolver();
        $previousApplication = DB::getFacadeApplication();
        $previousDatabase = $previousApplication !== null && ! $previousApplication->bound('db') ? null : DB::getFacadeRoot();
        $connection = new class(null) extends PostgresConnection
        {
            public array $statements = [];
            public array $readModes = [];

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                $this->statements[] = $query;
                $this->readModes[] = $useReadPdo;
                if (str_contains($query, 'AS fingerprint')) {
                    return [(object) ['indexed_count' => 6, 'schema2_count' => 6, 'fingerprint' => 'full-index',
                        'category_counts' => '[{"category":"IFCBUILDINGELEMENTPROXY","element_count":6}]']];
                }
                if (str_contains($query, 'AS equipment_count_kind')) {
                    return [(object) ['equipment_count_kind' => 'pump', 'equipment_count' => 2],
                        (object) ['equipment_count_kind' => 'ventilation_unit', 'equipment_count' => 3]];
                }
                return [];
            }
        };
        $resolver = new ConnectionResolver(['offline' => $connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
        DB::swap($connection);
        try {
            $scope = (new Builder($connection->query()))->setModel(new DesignIfcModelElement)
                ->useWritePdo()
                ->where('design_ifc_model_elements.organization_id', 1)
                ->whereIn('design_ifc_model_elements.id', $connection->query()->from('authorized_bim_elements')->select('id'));
            $result = (new AssistantBimIndexQuery(new AssistantBimIndexProjection, new AssistantBimIndexPredicate))->read($scope,
                ['offset' => 0, 'limit' => 20, 'include_properties' => false, 'include_quantities' => false,
                    'equipment_kinds' => ['pump', 'ventilation_unit']], [], []);
            self::assertSame(5, $result['total']);
            self::assertSame(['pump' => 2, 'ventilation_unit' => 3], $result['equipment_counts']);
            self::assertSame(6, $result['scanned_count']);
            self::assertSame('full-index', $result['index_fingerprint']);
            self::assertSame([['category' => 'IFCBUILDINGELEMENTPROXY', 'count' => 6]], $result['categories']);
            self::assertCount(3, $connection->statements);
            self::assertFalse($connection->readModes[0]);
            foreach ($connection->statements as $sql) {
                self::assertStringContainsString('"design_ifc_model_elements"."organization_id" = ?', $sql);
                self::assertStringContainsString('"design_ifc_model_elements"."id" in (select "id" from "authorized_bim_elements")', $sql);
            }
        } finally {
            if ($previousDatabase !== null) { DB::swap($previousDatabase); }
            else {
                DB::clearResolvedInstance('db');
                $previousApplication?->forgetInstance('db');
            }
            if ($previousResolver !== null) { Model::setConnectionResolver($previousResolver); }
            else { Model::unsetConnectionResolver(); }
        }
    }

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
                    return [(object) ['indexed_count' => 0, 'schema2_count' => 0, 'fingerprint' => 'empty-index']];
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
            self::assertSame(0, $result['scanned_count']);
            self::assertSame(0, $result['schema2_row_count']);
            self::assertSame('empty-index', $result['index_fingerprint']);
            self::assertCount(3, $connection->statements);
            $fingerprint = array_values(array_filter($connection->statements, static fn (string $sql): bool => str_contains($sql, 'AS fingerprint')))[0];
            self::assertStringStartsWith('WITH _assistant_index_scope AS MATERIALIZED', $fingerprint);
            self::assertStringContainsString('COUNT(*) FILTER (WHERE source_schema_version = 2) AS schema2_count', $fingerprint);
            self::assertStringContainsString('source_content_hash', $fingerprint);
            self::assertStringContainsString('ORDER BY id', $fingerprint);
            self::assertStringNotContainsString('order by "design_ifc_model_elements"."name"', $fingerprint);
            self::assertStringContainsString('"design_ifc_model_elements"."organization_id" = ?', $fingerprint);
            self::assertStringContainsString('"design_ifc_model_elements"."id" in (select "id" from "authorized_bim_elements")', $fingerprint);
            $categories = array_values(array_filter($connection->statements, static fn (string $sql): bool => str_contains($sql, 'element_count')))[0];
            self::assertSame($fingerprint, $categories);
            self::assertStringContainsString('SELECT category, COUNT(*) AS element_count FROM _assistant_index_scope', $categories);
            self::assertStringNotContainsString('order by "design_ifc_model_elements"."name"', $categories);
        } finally {
            if ($previousResolver !== null) { Model::setConnectionResolver($previousResolver); }
            else { Model::unsetConnectionResolver(); }
        }
    }
}
