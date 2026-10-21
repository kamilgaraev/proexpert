<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeUnitNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\ResolveSelectedNormativeResources;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\EstimateGeneration\EstimateGenerationCanonicalPostgresTestCase;

final class OfficialNormativeResourceOraclePostgresTest extends EstimateGenerationCanonicalPostgresTestCase
{
    public function test_historical_official_norm_preserves_source_units_and_scales_the_transcribed_labor_resource(): void
    {
        [$fixture, $catalog, $item, $context] = $this->fixture();
        self::assertSame('100 м3', $fixture['norm']['unit']);
        self::assertSame('чел.-ч', $fixture['norm']['resources'][0]['unit']);
        $parsed = NormativeUnitNormalizer::parseDetailed($fixture['norm']['unit']);
        self::assertSame('100 м3', $parsed->raw);
        self::assertSame('volume', $parsed->dimension);
        self::assertSame('м3', $parsed->baseUnit);
        self::assertSame(100.0, $parsed->multiplier);
        $factor = NormativeUnitNormalizer::safeQuantityFactorDecimal($item['unit'], $fixture['norm']['unit']);
        self::assertSame($fixture['oracle']['quantity_factor'], $factor);
        self::assertSame($fixture['oracle']['norm_quantity'], (string) BigDecimal::of($item['quantity'])
            ->multipliedBy($factor)->strippedOfTrailingZeros());

        $result = app(ResolveSelectedNormativeResources::class)->resolveWithDiagnostics($item, $catalog['norm_id'], $context);
        self::assertSame([], $result['blocking_issues'], json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        self::assertNotNull($result['item']);
        $assembled = $result['item'];
        self::assertSame($item['quantity'], $assembled['quantity']);
        self::assertSame($item['unit'], $assembled['unit']);
        self::assertSame($fixture['norm']['unit'], $assembled['normative_match']['unit']);
        self::assertSame($fixture['norm']['code'], $assembled['normative_match']['code']);
        self::assertSame($context['regional_context']['normative_dataset_version'], $assembled['normative_match']['dataset_version']['version_key']);
        self::assertCount(1, $assembled['labor']);
        $labor = $assembled['labor'][0];
        self::assertSame($fixture['norm']['resources'][0]['unit'], $labor['unit']);
        self::assertSame($fixture['norm']['resources'][0]['quantity'], (string) $labor['quantity_per_unit']);
        self::assertSame($fixture['oracle']['labor_per_work_unit'], $labor['quantity_per_work_unit']);
        self::assertSame($fixture['oracle']['labor_hours'], (string) $labor['quantity']);
        self::assertCount($fixture['oracle']['materials_count'], $assembled['materials']);
        self::assertCount($fixture['oracle']['machinery_count'], $assembled['machinery']);
        self::assertSame($fixture['norm']['resources'][0]['code'], $labor['normative_ref']['resource_code']);
        self::assertSame($catalog['norm_id'], $labor['normative_ref']['norm_id']);
        self::assertSame($catalog['resource_id'], $labor['normative_ref']['norm_resource_id']);
        self::assertSame($catalog['price_id'], $labor['normative_ref']['price_id']);
        self::assertSame($fixture['synthetic_price_context']['unit_price'], (string) $labor['unit_price']);
        self::assertArrayNotHasKey('quantity_evidence', $assembled);
        self::assertArrayNotHasKey('specialization_scenario', $assembled);
        self::assertSame('unresolved', $fixture['scenario']['engineering_applicability']);
        self::assertFalse($fixture['source']['current_edition_verified']);
        self::assertFalse($fixture['source']['official_xml_import_verified']);
        self::assertFalse($fixture['synthetic_price_context']['official_price_verified']);

        $collection = DB::table('estimate_norm_collections')->where('id', $catalog['collection_id'])->first();
        self::assertSame($catalog['dataset_id'], $collection->dataset_version_id);
        self::assertSame('tests/Fixtures/EstimateGeneration/normatives/gesn-2022-378pr-01-02-057-01.json', $collection->source_file);
        $meta = json_decode(DB::table('estimate_dataset_versions')->where('id', $catalog['dataset_id'])->value('meta'), true, flags: JSON_THROW_ON_ERROR);
        self::assertEquals($fixture['source'], $meta['transcribed_pdf_source']);
        $source = json_decode(DB::table('estimate_norms')->where('id', $catalog['norm_id'])->value('raw_payload'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($fixture['source']['pdf_sha256'], $source['transcribed_pdf_source']['pdf_sha256']);
        self::assertSame(129, $source['transcribed_pdf_source']['norm_page']);
        Http::assertNothingSent();
    }

    public function test_incompatible_project_unit_cannot_receive_the_transcribed_norm_or_resources(): void
    {
        [, $catalog, $item, $context] = $this->fixture();
        $before = $item;
        $resolver = app(ResolveSelectedNormativeResources::class);
        self::assertNotNull($resolver->resolve($item, $catalog['norm_id'], $context));
        $result = $resolver->resolveWithDiagnostics([...$item, 'unit' => 'м2'], $catalog['norm_id'], $context);
        self::assertNull($result['item']);
        self::assertSame(['normative_resource_context_not_approved'], $result['blocking_issues']);
        self::assertSame($before, $item);
        self::assertSame('100 м3', DB::table('estimate_norms')->where('id', $catalog['norm_id'])->value('unit'));
        Http::assertNothingSent();
    }

    private function fixture(): array
    {
        Http::fake([]);
        Http::preventStrayRequests();
        Queue::fake();
        config(['cache.default' => 'array']);
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/EstimateGeneration/normatives/gesn-2022-378pr-01-02-057-01.json')),
            true, flags: JSON_THROW_ON_ERROR);
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->createQuietly(['address' => null]);
        $actor = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $actor->id, 'status' => 'draft', 'state_version' => 0, 'input_payload' => []]);
        $catalog = $this->catalog($fixture);
        $item = ['key' => 'official-historical-trench-resource-oracle', 'name' => $fixture['norm']['name'],
            'unit' => $fixture['scenario']['unit'], 'quantity' => $fixture['scenario']['quantity'], 'source_refs' => [],
            'materials' => [], 'labor' => [], 'machinery' => [], 'validation_flags' => []];

        return [$fixture, $catalog, $item, ['organization_id' => (int) $organization->id, 'project_id' => (int) $project->id,
            'session_id' => (int) $session->id, 'regional_context' => $catalog['context']]];
    }

    private function catalog(array $fixture): array
    {
        $now = now();
        $suffix = strtolower((string) Str::ulid());
        $key = 'gesn2022-378pr-transcribed-'.$suffix;
        $dataset = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fsnb_2022', 'version_key' => $key,
            'bucket' => 'test', 'prefix' => $key, 'status' => 'parsed', 'meta' => json_encode([
                'fixture_kind' => $fixture['fixture_kind'], 'transcribed_pdf_source' => $fixture['source'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        $prices = DB::table('estimate_dataset_versions')->insertGetId(['source_type' => 'fgis_labor_prices', 'version_key' => $key.'-synthetic-prices',
            'bucket' => 'test', 'prefix' => $key.'-synthetic-prices', 'status' => 'parsed', 'created_at' => $now, 'updated_at' => $now]);
        $collection = DB::table('estimate_norm_collections')->insertGetId(['dataset_version_id' => $dataset,
            'code' => $fixture['norm']['collection_code'], 'name' => $fixture['norm']['collection_name'], 'norm_type' => 'gesn',
            'source_file' => 'tests/Fixtures/EstimateGeneration/normatives/gesn-2022-378pr-01-02-057-01.json', 'created_at' => $now, 'updated_at' => $now]);
        $section = DB::table('estimate_norm_sections')->insertGetId(['collection_id' => $collection, 'code' => $fixture['norm']['section_code'],
            'name' => $fixture['norm']['collection_name'], 'path' => '01/manual-transcription', 'created_at' => $now, 'updated_at' => $now]);
        $norm = DB::table('estimate_norms')->insertGetId(['collection_id' => $collection, 'code' => $fixture['norm']['code'],
            'section_code' => $fixture['norm']['section_code'], 'section_name' => $fixture['norm']['collection_name'], 'section_id' => $section,
            'name' => $fixture['norm']['name'], 'unit' => $fixture['norm']['unit'],
            'work_composition' => json_encode($fixture['norm']['work_composition'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'raw_payload' => json_encode(['transcribed_pdf_source' => $fixture['source']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now, 'updated_at' => $now]);
        $resource = $fixture['norm']['resources'][0];
        $resourceId = DB::table('estimate_norm_resources')->insertGetId(['estimate_norm_id' => $norm, 'resource_code' => $resource['code'],
            'resource_name' => $resource['name'], 'unit' => $resource['unit'], 'quantity' => $resource['quantity'],
            'resource_type' => $resource['type'], 'created_at' => $now, 'updated_at' => $now]);
        $region = DB::table('estimate_regions')->insertGetId(['code' => 'ORACLE-'.substr($suffix, -10), 'name' => 'Synthetic oracle region',
            'fgiscs_subject_id' => random_int(100000, 999999), 'created_at' => $now, 'updated_at' => $now]);
        $zone = DB::table('estimate_price_zones')->insertGetId(['estimate_region_id' => $region, 'name' => 'Synthetic oracle zone',
            'fgiscs_price_zone_id' => random_int(1000000, 1999999), 'created_at' => $now, 'updated_at' => $now]);
        $synthetic = $fixture['synthetic_price_context'];
        $period = DB::table('estimate_price_periods')->where('year', $synthetic['year'])->where('quarter', $synthetic['quarter'])->value('id')
            ?? DB::table('estimate_price_periods')->insertGetId(['fgiscs_period_id' => random_int(2000000, 2999999), 'name' => 'Synthetic historical oracle period',
                'year' => $synthetic['year'], 'quarter' => $synthetic['quarter'], 'created_at' => $now, 'updated_at' => $now]);
        $version = DB::table('estimate_regional_price_versions')->insertGetId(['source' => 'fgiscs', 'region_id' => $region,
            'price_zone_id' => $zone, 'period_id' => $period, 'version_key' => $key.'-synthetic-prices', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $price = DB::table('estimate_resource_prices')->insertGetId(['dataset_version_id' => $prices, 'regional_price_version_id' => $version,
            'region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period, 'resource_code' => $resource['code'],
            'resource_name' => $resource['name'], 'unit' => $synthetic['unit'], 'base_price' => $synthetic['unit_price'], 'price_type' => 'labor',
            'raw_payload' => json_encode(['synthetic_price_context' => $synthetic], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => $now, 'updated_at' => $now]);
        DB::table('estimate_regional_price_versions')->where('id', $version)->update(['status' => 'active']);

        return ['norm_id' => $norm, 'resource_id' => $resourceId, 'price_id' => $price, 'dataset_id' => $dataset, 'collection_id' => $collection,
            'context' => ['region_id' => $region, 'price_zone_id' => $zone, 'period_id' => $period,
                'business_date' => $synthetic['business_date'], 'year' => $synthetic['year'], 'quarter' => $synthetic['quarter'],
                'version_key' => $key.'-synthetic-prices', 'estimate_regional_price_version_id' => $version, 'normative_dataset_version' => $key]];
    }
}
