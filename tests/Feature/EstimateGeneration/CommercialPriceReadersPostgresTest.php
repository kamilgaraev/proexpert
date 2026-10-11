<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\CataloguePriceSnapshotReader;
use App\BusinessModules\Addons\EstimateGeneration\Pricing\SupplierPriceSnapshotReader;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalVersion;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\EstimatePositionCatalog;
use App\Models\MeasurementUnit;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Support\CreatesCanonicalProcurementSelection;
use Tests\TestCase;

final class CommercialPriceReadersPostgresTest extends TestCase
{
    use CreatesCanonicalProcurementSelection;

    public function test_catalog_reader_does_not_invent_price_conditions_and_rejects_foreign_or_deleted_records(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        $organization = Organization::factory()->create();
        $foreign = Organization::factory()->create();
        $creator = User::factory()->create(['current_organization_id' => $organization->id]);
        $unit = MeasurementUnit::query()->firstOrCreate(['organization_id' => $organization->id, 'short_name' => 'м²'], ['name' => 'Квадратный метр', 'type' => 'area']);
        $record = EstimatePositionCatalog::query()->create(['organization_id' => $organization->id, 'name' => 'Отделка пола', 'code' => 'PRICE-FINISH-1',
            'item_type' => 'work', 'measurement_unit_id' => $unit->id, 'unit_price' => '150.25', 'is_active' => true, 'created_by_user_id' => $creator->id]);
        $reader = app(CataloguePriceSnapshotReader::class);
        $source = $reader->read((int) $organization->id, 'catalog:'.$record->id);
        self::assertSame('150.25', $source['unit_price']);
        self::assertSame('m2', $source['unit']);
        self::assertNull($source['currency']);
        self::assertNull($source['as_of_date']);
        self::assertContains('as_of_date', $source['conditions_missing']);
        self::assertNull($reader->read((int) $foreign->id, 'catalog:'.$record->id));
        $record->metadata = ['currency' => 'RUB', 'price_as_of_date' => '2026-10-01', 'price_valid_until' => '2026-10-05',
            'price_region' => 'Москва', 'vat_mode' => 'included', 'vat_rate' => '20', 'delivery_included' => true];
        $record->save();
        $priced = $reader->read((int) $organization->id, 'catalog:'.$record->id, new DateTimeImmutable('2026-10-11'));
        self::assertTrue($priced['stale']);
        self::assertFalse($priced['stale_accepted']);
        self::assertNotSame($source['source_hash'], $priced['source_hash']);
        $record->updated_at = now()->addHour();
        $record->save();
        self::assertSame($priced['source_hash'], $reader->read((int) $organization->id, 'catalog:'.$record->id)['source_hash']);
        $record->metadata = [...$record->metadata, 'price_as_of_date' => '2027-01-01', 'vat_rate' => 'invalid'];
        $record->save();
        $unusable = $reader->read((int) $organization->id, 'catalog:'.$record->id);
        self::assertFalse($unusable['applicable']);
        self::assertContains('vat_rate', $unusable['conditions_missing']);
        $record->delete();
        self::assertNull($reader->read((int) $organization->id, 'catalog:'.$record->id));
    }

    public function test_supplier_reader_uses_real_immutable_snapshot_and_full_project_lineage(): void
    {
        $organization = Organization::factory()->create();
        $request = $this->createCanonicalSelectionSupplierRequest($organization, 10000, 'PRICE-PR-1', 'PRICE-SR-1');
        $proposal = $this->createCanonicalSelectionProposal($organization, $request, 'PRICE-CP-1', 250, vatAmount: 41.67);
        $version = SupplierProposalVersion::query()->where('supplier_proposal_id', $proposal->id)->firstOrFail();
        $line = $version->commercial_snapshot['lines'][0];
        $projectId = (int) $request->purchaseRequest->siteRequest->project_id;
        $actor = User::factory()->create(['current_organization_id' => $organization->id, 'is_active' => true]);
        $actor->organizations()->attach($organization->id, ['is_active' => true, 'project_access_mode' => 'all_projects']);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->allows('canCurrent')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $projectId,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $reader = app(SupplierPriceSnapshotReader::class);
        $reference = 'supplier:'.$version->id.':'.$line['id'];
        $source = $reader->read($actor, $session, $reference, new DateTimeImmutable('today'));
        self::assertSame('250', $source['unit_price']);
        self::assertSame((int) $line['supplier_request_line_id'], $source['supplier_request_line_id']);
        self::assertFalse($source['applicable']); // The source has no proven material/SKU mapping.
        self::assertSame('included', $source['vat_mode']);
        $other = Project::factory()->for($organization)->create();
        $otherSession = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $other->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        self::assertNull($reader->read($actor, $otherSession, $reference, new DateTimeImmutable('today')));
        $actor->is_active = false;
        $actor->save();
        $this->expectException(AuthorizationException::class);
        $reader->read($actor, $session, $reference, new DateTimeImmutable('today'));
    }
}
