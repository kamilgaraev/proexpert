<?php

declare(strict_types=1);

namespace Tests\Unit\WorkTypeMaterial;

use App\Http\Requests\Api\V1\Admin\WorkTypeMaterial\StoreWorkTypeMaterialRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class StoreWorkTypeMaterialRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_material_id_must_belong_to_current_organization_when_context_is_available(): void
    {
        $ownOrganization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();

        DB::table('materials')->insert([
            [
                'id' => 1,
                'organization_id' => $ownOrganization->id,
                'name' => 'Own material',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'organization_id' => $otherOrganization->id,
                'name' => 'Other material',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $request = StoreWorkTypeMaterialRequest::create('/work-type-materials', 'POST', []);
        $request->attributes->set('current_organization_id', $ownOrganization->id);
        $request->setUserResolver(
            static fn (): User => new User(['current_organization_id' => $ownOrganization->id])
        );

        $validator = Validator::make([
            'materials' => [
                ['material_id' => 2, 'default_quantity' => 1],
            ],
        ], $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('materials.0.material_id', $validator->errors()->toArray());
    }
}
