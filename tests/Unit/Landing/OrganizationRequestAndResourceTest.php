<?php

declare(strict_types=1);

namespace Tests\Unit\Landing;

use App\Http\Requests\Api\V1\Landing\Organization\StoreOrganizationRequest;
use App\Http\Resources\Api\V1\Landing\Organization\OrganizationSummaryResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrganizationRequestAndResourceTest extends TestCase
{
    public function refreshDatabase(): void
    {
    }

    public function test_store_organization_request_requires_name(): void
    {
        $request = new StoreOrganizationRequest();

        $this->assertSame('required|string|max:255|min:2', $request->rules()['name']);
        $this->assertSame(
            trans_message('organization.validation.name_required'),
            $request->messages()['name.required']
        );
    }

    public function test_organization_summary_resource_exposes_only_list_fields(): void
    {
        $organization = new Organization([
            'name' => 'МОСТ',
            'legal_name' => 'ООО МОСТ',
            'tax_number' => '1234567890',
            'email' => 'company@example.test',
            'is_active' => true,
            'verification_status' => 'verified',
        ]);
        $organization->id = 15;

        $resource = new OrganizationSummaryResource($organization);
        $payload = $resource->toArray(Request::create('/'));

        $this->assertSame([
            'id' => 15,
            'name' => 'МОСТ',
            'is_active' => true,
            'verification_status' => 'verified',
        ], $payload);
    }
}
