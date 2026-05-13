<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Models\NormativeBaseType;
use App\Models\NormativeCollection;
use App\Models\NormativeRate;
use App\Models\NormativeSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class NormativeRateControllerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_paginated_admin_response_for_admin_ui(): void
    {
        $context = AdminApiTestContext::create();
        $baseType = $this->createBaseType();
        $collection = $this->createCollection($baseType);
        $section = $this->createSection($collection);
        $rate = $this->createRate($collection, $section, [
            'code' => 'FER-01-001',
            'name' => 'Concrete placement',
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimates/normative-rates/search?query=FER-01&type=code&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $rate->id);
        $response->assertJsonPath('data.0.collection.id', $collection->id);
        $response->assertJsonPath('data.0.section.id', $section->id);
    }

    public function test_collections_sections_and_rate_detail_use_admin_contracts(): void
    {
        $context = AdminApiTestContext::create();
        $baseType = $this->createBaseType();
        $collection = $this->createCollection($baseType);
        $section = $this->createSection($collection);
        $rate = $this->createRate($collection, $section);

        $collectionsResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimates/normative-rates/collections');
        $sectionsResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimates/normative-rates/collections/{$collection->id}/sections");
        $rateResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/estimates/normative-rates/{$rate->id}");
        $missingRateResponse = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/estimates/normative-rates/999999');

        $collectionsResponse->assertOk();
        $collectionsResponse->assertJsonPath('success', true);
        $collectionsResponse->assertJsonPath('data.0.id', $collection->id);

        $sectionsResponse->assertOk();
        $sectionsResponse->assertJsonPath('success', true);
        $sectionsResponse->assertJsonPath('data.0.id', $section->id);

        $rateResponse->assertOk();
        $rateResponse->assertJsonPath('success', true);
        $rateResponse->assertJsonPath('data.id', $rate->id);
        $rateResponse->assertJsonPath('data.resources', []);

        $missingRateResponse->assertNotFound();
        $missingRateResponse->assertJsonPath('success', false);
    }

    private function createBaseType(): NormativeBaseType
    {
        return NormativeBaseType::query()->create([
            'code' => 'fer',
            'name' => 'ФЕР',
            'is_active' => true,
        ]);
    }

    private function createCollection(NormativeBaseType $baseType): NormativeCollection
    {
        return NormativeCollection::query()->create([
            'base_type_id' => $baseType->id,
            'code' => '01',
            'name' => 'Building works',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }

    private function createSection(NormativeCollection $collection): NormativeSection
    {
        return NormativeSection::query()->create([
            'collection_id' => $collection->id,
            'code' => '01-01',
            'name' => 'Earth and concrete works',
            'level' => 0,
            'sort_order' => 1,
        ]);
    }

    private function createRate(NormativeCollection $collection, NormativeSection $section, array $overrides = []): NormativeRate
    {
        return NormativeRate::query()->create(array_merge([
            'collection_id' => $collection->id,
            'section_id' => $section->id,
            'code' => 'FER-01-001',
            'name' => 'Concrete placement',
            'measurement_unit' => 'm3',
            'base_price' => 1200,
            'materials_cost' => 700,
            'machinery_cost' => 250,
            'labor_cost' => 250,
            'labor_hours' => 3,
            'machinery_hours' => 1,
            'base_price_year' => '2000',
        ], $overrides));
    }
}
