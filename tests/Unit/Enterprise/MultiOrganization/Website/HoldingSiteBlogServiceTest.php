<?php

declare(strict_types=1);

namespace Tests\Unit\Enterprise\MultiOrganization\Website;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\HoldingSiteBlogService;
use App\Enums\Blog\BlogContextEnum;
use App\Models\Blog\BlogCategory;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\User;
use Tests\TestCase;

class HoldingSiteBlogServiceTest extends TestCase
{
    public function test_default_category_uses_unique_slug_for_each_holding_group(): void
    {
        $service = new HoldingSiteBlogService();
        $firstGroup = $this->createOrganizationGroup('Первый холдинг', 'first-holding');
        $secondGroup = $this->createOrganizationGroup('Второй холдинг', 'second-holding');

        $firstCategory = $service->defaultCategory($firstGroup);
        $secondCategory = $service->defaultCategory($secondGroup);

        $this->assertSame('holding-news', $firstCategory->slug);
        $this->assertNotSame($firstCategory->id, $secondCategory->id);
        $this->assertSame($secondGroup->id, $secondCategory->organization_group_id);
        $this->assertSame(BlogContextEnum::HOLDING, $secondCategory->blog_context);
        $this->assertStringStartsWith('holding-news-', $secondCategory->slug);
        $this->assertCount(2, BlogCategory::query()->holding()->get());
    }

    private function createOrganizationGroup(string $name, string $slug): OrganizationGroup
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        return OrganizationGroup::create([
            'name' => $name,
            'slug' => $slug,
            'parent_organization_id' => $organization->id,
            'created_by_user_id' => $user->id,
            'status' => 'active',
        ]);
    }
}
