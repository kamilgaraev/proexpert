<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCollectorQueryContext;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\SalesSupplierRagSource;
use App\Models\Organization;
use App\Models\Supplier;
use App\Services\Monitoring\SlowQueryContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RagCollectorQueryAttributionTest extends TestCase
{
    public function test_sales_pages_keep_source_tenant_boundaries_and_leave_consumer_queries_unattributed(): void
    {
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $ids = Model::withoutEvents(static function () use ($organization, $foreign): array {
            Supplier::query()->create(['organization_id' => $foreign->id, 'name' => 'Foreign supplier']);
            $ids = [];
            for ($position = 0; $position < 51; $position++) {
                $ids[] = Supplier::query()->create(['organization_id' => $organization->id, 'name' => 'Supplier '.$position])->id;
            }

            return $ids;
        });
        $reads = [];
        $capture = true;
        DB::listen(static function (QueryExecuted $query) use (&$reads, &$capture): void {
            if ($capture && str_starts_with($query->sql, 'select "suppliers".')) {
                $reads[] = [
                    'identity' => RagCollectorQueryContext::current(),
                    'source' => SlowQueryContext::source(['tests/Feature/AIAssistant/RagCollectorQueryAttributionTest.php']),
                ];
            }
        });
        $source = new SalesSupplierRagSource;
        $chunks = [];
        foreach ($source->collectForOrganization($organization->id) as $chunk) {
            $chunks[] = $chunk;
            self::assertNull(RagCollectorQueryContext::current());
            self::assertSame($organization->id, $chunk->organizationId);
        }
        $capture = false;
        self::assertSame($ids, array_column($chunks, 'entityId'));
        self::assertCount(2, $reads);
        foreach ($reads as $read) {
            self::assertSame(['source_type' => 'sales_supplier_catalog', 'entity_type' => 'supplier', 'phase' => 'collection_page'], $read['identity']);
            self::assertSame('app/BusinessModules/Features/AIAssistant/Services/Rag/Sources/SalesBusinessRagSource.php', $read['source']['file']);
        }
        $capture = true;
        $reads = [];
        self::assertEquals([$chunks[0]], [...$source->collectEntity($organization->id, 'supplier', $ids[0])]);
        $capture = false;
        self::assertCount(1, $reads);
        self::assertNull($reads[0]['identity']);
        self::assertSame([], [...$source->collectEntity($foreign->id, 'supplier', $ids[0])]);
    }
}
