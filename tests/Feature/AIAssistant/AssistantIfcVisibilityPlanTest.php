<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AssistantIfcVisibilityPlanTest extends TestCase
{
    protected function connectionsToTransact(): array
    {
        return [];
    }

    public function test_visibility_map_changes_covering_read_cost_without_changing_component_eligibility(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        self::assertMatchesRegularExpression('/^most_phpunit_[a-f0-9]+_testing$/', DB::connection()->getDatabaseName());
        self::assertSame(0, DB::transactionLevel());
        self::assertSame(16, intdiv((int) DB::selectOne("SELECT current_setting('server_version_num') AS version")->version, 10000));
        $names = ['search_path', 'work_mem', 'jit', 'max_parallel_workers_per_gather', 'enable_seqscan', 'enable_bitmapscan', 'statement_timeout', 'lock_timeout'];
        $original = [];
        foreach ($names as $name) {
            $original[$name] = DB::selectOne('SELECT current_setting(?) AS value', [$name])->value;
        }
        $schema = 'rag_ifc_vm_'.bin2hex(random_bytes(6));
        self::assertMatchesRegularExpression('/^rag_ifc_vm_[a-f0-9]{12}$/', $schema);
        $prefix = '"'.$schema.'".';
        DB::statement('CREATE SCHEMA "'.$schema.'"');
        try {
            foreach (['search_path'=>$schema, 'work_mem'=>'16MB', 'jit'=>'off', 'max_parallel_workers_per_gather'=>'0', 'enable_seqscan'=>'on', 'enable_bitmapscan'=>'on', 'statement_timeout'=>'120s', 'lock_timeout'=>'2s'] as $name => $value) {
                DB::selectOne('SELECT set_config(?, ?, false)', [$name, $value]);
            }
            self::assertSame($schema, DB::selectOne('SELECT current_schema() AS name')->name);
            $this->createFixture($prefix);
            $native = $this->nativeSql($prefix);
            $identities = $this->identitySql($prefix, $native);
            $counts = 'SELECT source_type, COUNT(*) AS stored_count, COALESCE(SUM(chunk_count),0) AS stored_chunk_count, COUNT(CASE WHEN indexed_chunk_count>0 THEN 1 END) AS indexed_source_count FROM ('.$identities.') accessible_sources GROUP BY source_type';
            DB::statement('CREATE TABLE '.$prefix.'baseline_native AS '.$native);
            DB::statement('CREATE TABLE '.$prefix.'baseline_identity AS '.$identities);
            $beforeCounts = array_map(static fn (object $row): array => (array) $row, DB::select($counts));
            self::assertSame(225000, (int) DB::selectOne('SELECT COUNT(*) AS count FROM '.$prefix.'baseline_native')->count);
            self::assertSame(205002, (int) $beforeCounts[0]['stored_count']);
            self::assertSame(410006, (int) $beforeCounts[0]['stored_chunk_count']);
            self::assertSame(102502, (int) $beforeCounts[0]['indexed_source_count']);
            $before = $this->samplePhase('partially_visible', $prefix, $native, $counts);
            self::assertGreaterThan(0.45, $before['relation']['visible_fraction']);
            self::assertLessThan(0.60, $before['relation']['visible_fraction']);
            DB::statement('VACUUM (FREEZE, DISABLE_PAGE_SKIPPING) '.$prefix.'design_ifc_model_elements');
            DB::statement('ANALYZE '.$prefix.'design_ifc_model_elements');
            $after = $this->samplePhase('fully_visible', $prefix, $native, $counts);
            self::assertSame($beforeCounts, array_map(static fn (object $row): array => (array) $row, DB::select($counts)));
            self::assertSame(0, $this->multisetDifferenceCount($prefix.'baseline_native', $native));
            self::assertSame(0, $this->multisetDifferenceCount($prefix.'baseline_identity', $identities));
            self::assertSame($before['relation']['pages'], $after['relation']['pages']);
            self::assertSame($before['relation']['rows'], $after['relation']['rows']);
            self::assertSame(1.0, $after['relation']['visible_fraction']);
            self::assertContains('ifc_access_identity_idx', $before['forced_cover'][0]['indexes']);
            self::assertContains('ifc_access_identity_idx', $after['forced_cover'][0]['indexes']);
            self::assertGreaterThan(0, $before['forced_cover'][0]['ifc_heap_fetches']);
            self::assertSame(0, $after['forced_cover'][0]['ifc_heap_fetches']);
            fwrite(STDERR, "\nRAG_IFC_VISIBILITY_COMPONENT_PLANS ".json_encode([
                'component_only'=>true,
                'fixture'=>['rows'=>225005, 'eligible_rows'=>225000, 'inline_payload_bytes'=>1024, 'initial_vacuum_rows'=>119250, 'appended_rows'=>105750, 'rejected_native_rows'=>5, 'source_rows'=>205015, 'parallel_workers'=>0, 'new_indexes'=>false, 'concurrent_writer'=>false],
                'exact_native_multiset_parity'=>true,
                'exact_identity_multiset_parity'=>true,
                'count_parity'=>true,
                'before'=>$before,
                'after'=>$after,
            ], JSON_THROW_ON_ERROR)."\n");
        } finally {
            try {
                DB::statement('DROP SCHEMA "'.$schema.'" CASCADE');
            } finally {
                foreach ($original as $name => $value) {
                    DB::selectOne('SELECT set_config(?, ?, false)', [$name, $value]);
                }
            }
        }
    }

    private function createFixture(string $prefix): void
    {
        DB::statement('CREATE TABLE '.$prefix.'projects (id bigint PRIMARY KEY, organization_id bigint NOT NULL)');
        DB::statement('CREATE TABLE '.$prefix.'design_artifact_versions (id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint)');
        DB::statement('CREATE TABLE '.$prefix.'design_model_derivatives (id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint, version_id bigint NOT NULL)');
        DB::statement('CREATE TABLE '.$prefix.'design_ifc_model_elements (id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint, version_id bigint NOT NULL, derivative_id bigint, express_id bigint NOT NULL, payload text NOT NULL) WITH (autovacuum_enabled=false, fillfactor=100)');
        DB::statement('ALTER TABLE '.$prefix.'design_ifc_model_elements ALTER COLUMN payload SET STORAGE PLAIN');
        DB::statement('CREATE INDEX ifc_access_identity_idx ON '.$prefix.'design_ifc_model_elements (organization_id,project_id,version_id,derivative_id,id) INCLUDE(express_id)');
        DB::statement('CREATE TABLE '.$prefix.'ai_rag_status_sources (id bigint PRIMARY KEY, organization_id bigint NOT NULL, project_id bigint, source_type text NOT NULL, entity_type text NOT NULL, entity_id text NOT NULL, identity_part_key text NOT NULL, chunk_count integer NOT NULL, indexed_chunk_count integer NOT NULL) WITH (autovacuum_enabled=false)');
        DB::statement('CREATE INDEX source_coverage_idx ON '.$prefix.'ai_rag_status_sources (organization_id,source_type,entity_type,entity_id) INCLUDE(id,project_id,chunk_count,indexed_chunk_count)');
        DB::statement('INSERT INTO '.$prefix.'projects SELECT n,1 FROM generate_series(1,8) n');
        DB::statement('INSERT INTO '.$prefix.'design_artifact_versions SELECT n,1,CASE WHEN n=9 THEN NULL ELSE n END FROM generate_series(1,9) n');
        DB::statement('INSERT INTO '.$prefix.'design_model_derivatives SELECT id,organization_id,project_id,id FROM '.$prefix.'design_artifact_versions');
        $insert = 'INSERT INTO '.$prefix.'design_ifc_model_elements SELECT n,1,CASE WHEN n%20=0 THEN NULL ELSE n%8+1 END,CASE WHEN n%20=0 THEN 9 ELSE n%8+1 END,CASE WHEN n%5=0 THEN NULL ELSE n%8+1 END,n,repeat(md5(n::text),32) FROM generate_series(CAST(? AS bigint),CAST(? AS bigint)) n';
        DB::statement($insert, [1, 119250]);
        DB::statement('VACUUM (FREEZE, DISABLE_PAGE_SKIPPING) '.$prefix.'design_ifc_model_elements');
        DB::statement($insert, [119251, 225000]);
        DB::statement('INSERT INTO '.$prefix."design_ifc_model_elements VALUES
            (225001,2,1,1,NULL,225001,repeat('x',1024)),
            (225002,1,99,1,NULL,225002,repeat('x',1024)),
            (225003,1,2,1,NULL,225003,repeat('x',1024)),
            (225004,1,1,1,2,225004,repeat('x',1024)),
            (225005,1,1,99,NULL,225005,repeat('x',1024))");
        DB::statement('INSERT INTO '.$prefix."ai_rag_status_sources SELECT n,1,CASE WHEN n%20=0 THEN NULL ELSE n%8+1 END,'design_additional','design_ifc_model_element',n::text,'main',2,CASE WHEN n%2=0 THEN 1 ELSE 0 END FROM generate_series(1,205000) n");
        DB::statement('INSERT INTO '.$prefix."ai_rag_status_sources VALUES
            (205001,1,2,'design_additional','design_ifc_model_element','1','part-a',3,1),
            (205002,1,2,'design_additional','design_ifc_model_element','1','part-b',3,1),
            (205003,1,2,'design_additional','design_ifc_model_element','01','main',1,1),
            (205004,1,2,'design_additional','design_ifc_model_element','+1','main',1,1),
            (205005,1,2,'design_additional','design_ifc_model_element','1.0','main',1,1),
            (205006,1,2,'design_additional','design_ifc_model_element','9223372036854775808','main',1,1),
            (205007,1,2,'design_additional','design_ifc_model_element','not-numeric','main',1,1),
            (205008,2,2,'design_additional','design_ifc_model_element','1','main',1,1),
            (205009,1,99,'design_additional','design_ifc_model_element','1','main',1,1),
            (205010,1,2,'other','design_ifc_model_element','1','main',1,1)");
        DB::statement('INSERT INTO '.$prefix."ai_rag_status_sources SELECT 205010+n,1,1,'design_additional','design_ifc_model_element',(225000+n)::text,'main',1,1 FROM generate_series(1,5) n");
        foreach (['projects','design_artifact_versions','design_model_derivatives','ai_rag_status_sources'] as $table) {
            DB::statement('VACUUM (FREEZE, ANALYZE) '.$prefix.$table);
        }
        DB::statement('ALTER TABLE '.$prefix.'design_ifc_model_elements ALTER COLUMN organization_id SET STATISTICS 1000');
        DB::statement('ALTER TABLE '.$prefix.'design_ifc_model_elements ALTER COLUMN project_id SET STATISTICS 1000');
        DB::statement('ANALYZE '.$prefix.'design_ifc_model_elements');
    }

    private function nativeSql(string $prefix): string
    {
        return 'SELECT elements.id FROM '.$prefix.'design_ifc_model_elements elements WHERE elements.organization_id=1
            AND (elements.project_id IS NULL OR elements.project_id IN (SELECT id FROM '.$prefix.'projects WHERE organization_id=1))
            AND (elements.version_id,(elements.project_id IS NULL),COALESCE(elements.project_id,0)) IN
                (SELECT versions.id,(versions.project_id IS NULL),COALESCE(versions.project_id,0) FROM '.$prefix.'design_artifact_versions versions WHERE versions.organization_id=1
                    AND (versions.project_id IS NULL OR versions.project_id IN (SELECT id FROM '.$prefix.'projects WHERE organization_id=1)))
            AND (elements.derivative_id IS NULL OR (elements.derivative_id,elements.version_id,(elements.project_id IS NULL),COALESCE(elements.project_id,0)) IN
                (SELECT derivatives.id,derivatives.version_id,(derivatives.project_id IS NULL),COALESCE(derivatives.project_id,0) FROM '.$prefix.'design_model_derivatives derivatives WHERE derivatives.organization_id=1
                    AND (derivatives.project_id IS NULL OR derivatives.project_id IN (SELECT id FROM '.$prefix.'projects WHERE organization_id=1))
                    AND (derivatives.version_id,(derivatives.project_id IS NULL),COALESCE(derivatives.project_id,0)) IN
                        (SELECT versions.id,(versions.project_id IS NULL),COALESCE(versions.project_id,0) FROM '.$prefix.'design_artifact_versions versions WHERE versions.organization_id=1)))';
    }

    private function identitySql(string $prefix, string $native): string
    {
        return 'SELECT sources.id AS source_id,native.id AS entity_key,sources.source_type,sources.chunk_count,sources.indexed_chunk_count FROM ('.$native.') native JOIN
            (SELECT id,source_type,chunk_count,indexed_chunk_count,CASE WHEN pg_input_is_valid(entity_id,\'bigint\') THEN CASE WHEN CAST(CAST(entity_id AS bigint) AS text) COLLATE "C"=entity_id COLLATE "C" THEN CAST(entity_id AS bigint) END END AS entity_key
                FROM '.$prefix."ai_rag_status_sources WHERE organization_id=1 AND source_type='design_additional' AND entity_type='design_ifc_model_element'
                AND (project_id IS NULL OR project_id IN (SELECT id FROM ".$prefix.'projects WHERE organization_id=1))) sources ON sources.entity_key=native.id';
    }

    private function multisetDifferenceCount(string $baseline, string $sql): int
    {
        return (int) DB::selectOne('SELECT COUNT(*) AS count FROM ((SELECT * FROM '.$baseline.' EXCEPT ALL '.$sql.') UNION ALL ('.$sql.' EXCEPT ALL SELECT * FROM '.$baseline.')) differences')->count;
    }

    private function samplePhase(string $phase, string $prefix, string $native, string $counts): array
    {
        $relation = DB::selectOne('SELECT relpages,relallvisible,reltuples::bigint AS rows,pg_relation_size(oid) AS bytes FROM pg_class WHERE oid=?::regclass', [$prefix.'design_ifc_model_elements']);
        $result = ['phase'=>$phase, 'relation'=>['pages'=>(int) $relation->relpages, 'visible_pages'=>(int) $relation->relallvisible, 'visible_fraction'=>(float) $relation->relallvisible / max(1,(int) $relation->relpages), 'rows'=>(int) $relation->rows, 'bytes'=>(int) $relation->bytes], 'natural_native'=>[], 'natural_source_count'=>[], 'forced_cover'=>[]];
        foreach (range(1,2) as $unused) {
            $result['natural_native'][] = $this->plan($native);
            $result['natural_source_count'][] = $this->plan($counts);
        }
        DB::selectOne("SELECT set_config('enable_seqscan','off',false),set_config('enable_bitmapscan','off',false)");
        try {
            foreach (range(1,2) as $unused) {
                $result['forced_cover'][] = $this->plan($native);
            }
        } finally {
            DB::selectOne("SELECT set_config('enable_seqscan','on',false),set_config('enable_bitmapscan','on',false)");
        }

        return $result;
    }

    private function plan(string $sql): array
    {
        $raw = DB::selectOne('EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT JSON) '.$sql);
        $document = json_decode($raw->{'QUERY PLAN'}, true, 512, JSON_THROW_ON_ERROR)[0];
        $nodes = [];
        $indexes = [];
        $heapFetches = 0;
        $walk = static function (array $node, int $depth) use (&$walk, &$nodes, &$indexes, &$heapFetches): void {
            $safe = ['depth'=>$depth, 'type'=>$node['Node Type']];
            foreach (['Actual Rows','Actual Loops','Plan Rows','Plan Width','Heap Fetches','Shared Hit Blocks','Shared Read Blocks','Temp Read Blocks','Temp Written Blocks','Rows Removed by Filter'] as $field) {
                if (isset($node[$field])) {
                    $safe[$field] = $node[$field];
                }
            }
            if (($node['Relation Name'] ?? '') === 'design_ifc_model_elements') {
                $safe['ifc_relation'] = true;
                $heapFetches += $node['Heap Fetches'] ?? 0;
            }
            if (isset($node['Index Name']) && in_array($node['Index Name'], ['ifc_access_identity_idx','source_coverage_idx','design_ifc_model_elements_pkey','projects_pkey','design_artifact_versions_pkey','design_model_derivatives_pkey','ai_rag_status_sources_pkey'], true)) {
                $indexes[] = $node['Index Name'];
            }
            $nodes[] = $safe;
            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child, $depth+1);
            }
        };
        $walk($document['Plan'], 0);

        return ['execution_ms'=>$document['Execution Time'], 'planning_ms'=>$document['Planning Time'], 'shared_hit_blocks'=>$document['Plan']['Shared Hit Blocks'] ?? 0, 'shared_read_blocks'=>$document['Plan']['Shared Read Blocks'] ?? 0, 'temp_written_blocks'=>$document['Plan']['Temp Written Blocks'] ?? 0, 'ifc_heap_fetches'=>$heapFetches, 'indexes'=>array_values(array_unique($indexes)), 'nodes'=>$nodes];
    }
}
