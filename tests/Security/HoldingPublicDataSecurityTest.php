<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSite;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\HoldingSitePage;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Domain\Models\SiteContentBlock;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\HoldingPublicDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteBuilderDataService;
use App\BusinessModules\Enterprise\MultiOrganization\Website\Services\SiteManagementService;
use App\Http\Controllers\Api\V1\HoldingApiController;
use App\Http\Controllers\Api\V1\Landing\HoldingLandingController;
use App\Http\Controllers\HoldingController;
use App\Models\Organization;
use App\Models\OrganizationGroup;
use App\Models\User;
use App\Services\Landing\MultiOrganizationService;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

final class HoldingPublicDataSecurityTest extends TestCase
{
    public function test_unpublished_pages_are_not_exposed_through_any_public_endpoint(): void
    {
        [$group, $site] = $this->holding();
        $responses = $this->publicResponses($group, $site);
        $statuses = array_map(static fn ($response) => $response->getStatusCode(), $responses);
        $statuses['builder_empty'] = $this->app->make(SiteBuilderDataService::class)->buildPublishedPayload($site) === [];

        self::assertSame(['slug' => 404, 'root' => 404, 'subdomain_site' => 404, 'landing_site' => 404, 'builder_empty' => true], $statuses);
    }

    public function test_inactive_groups_sites_and_missing_snapshots_are_not_public(): void
    {
        [$group, $site] = $this->holding(true);
        $snapshot = $site->getPublishedPayload();
        foreach ([
            ['active', 'draft', true, $snapshot],
            ['inactive', 'published', true, $snapshot],
            ['active', 'published', false, $snapshot],
            ['active', 'published', true, null],
            ['active', 'published', true, []],
        ] as [$groupStatus, $status, $active, $payload]) {
            $group->update(['status' => $groupStatus]);
            $site->update(['status' => $status, 'is_active' => $active, 'published_payload' => $payload]);
            foreach ($this->publicResponses($group, $site) as $name => $response) {
                self::assertSame(404, $response->getStatusCode(), $name.' '.$groupStatus.' '.$status);
            }
        }
    }

    public function test_published_responses_contain_only_saved_public_fields_and_no_preview_capability(): void
    {
        [$group, $site] = $this->holding(true);
        $responses = $this->publicResponses($group, $site);
        foreach ($responses as $name => $response) {
            self::assertSame(200, $response->getStatusCode(), $name);
            $json = $response->getData(true)['data'];
            if (in_array($name, ['slug', 'root'], true)) {
                self::assertSame(['id' => $group->id, 'name' => 'Опубликованное имя', 'slug' => $group->slug, 'description' => 'Сохранённое описание'], $json['holding']);
                self::assertSame(['id' => $group->parent_organization_id, 'name' => 'Публичная компания', 'phone' => '+70000000000'], $json['parent_organization']);
                self::assertNull($json['stats']);
            } else {
                self::assertSame('Опубликованное имя', $json['organization']['holding']['name']);
                self::assertNotEmpty($json['blocks']);
                self::assertArrayNotHasKey('preview_url', $json['site']);
            }
            $encoded = json_encode($json, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            foreach (['private-verification', 'private-settings', 'private-permissions', 'child_organizations', 'preview_url'] as $privateValue) {
                self::assertStringNotContainsString($privateValue, $encoded, $name);
            }
        }
    }

    public function test_public_responses_do_not_follow_unpublished_live_changes(): void
    {
        $this->freezeTime();
        [$group, $site] = $this->holding(true);
        $before = array_map(static fn ($response) => $response->getData(true)['data'], $this->publicResponses($group, $site));
        $group->update(['name' => 'Новое закрытое имя', 'description' => 'Новое закрытое описание']);
        $group->parentOrganization->update(['name' => 'Закрытая компания', 'phone' => '+79999999999']);
        $site->update(['title' => 'Неопубликованный заголовок']);
        $site->contentBlocks()->firstOrFail()->update(['content' => ['html' => 'UNPUBLISHED-DRAFT-CONTENT']]);
        $after = array_map(static fn ($response) => $response->getData(true)['data'], $this->publicResponses($group, $site));

        self::assertSame($before, $after);
        self::assertStringNotContainsString('UNPUBLISHED-DRAFT-CONTENT', json_encode($after));
    }

    public function test_missing_snapshot_fields_do_not_fall_back_to_live_private_models(): void
    {
        [$group, $site] = $this->holding(true);
        $snapshot = $site->getPublishedPayload();
        unset($snapshot['organization'], $snapshot['site']);
        $site->update(['published_payload' => $snapshot]);
        $responses = $this->publicResponses($group, $site);
        self::assertSame(404, $responses['slug']->getStatusCode());
        self::assertSame(404, $responses['root']->getStatusCode());
        foreach (['subdomain_site', 'landing_site'] as $name) {
            self::assertSame(200, $responses[$name]->getStatusCode());
            $data = $responses[$name]->getData(true)['data'];
            self::assertSame(['holding' => [], 'organization' => []], $data['organization']);
            self::assertSame('Сайт холдинга', $data['site']['title']);
            self::assertSame('', $data['site']['seo_meta']['keywords']);
            self::assertSame('#2563eb', $data['site']['theme_config']['primary_color']);
            self::assertSame([], $data['site']['analytics_config']);
            self::assertArrayNotHasKey('preview_url', $data['site']);
            self::assertNotEmpty($data['blocks']);
        }
    }

    public function test_deactivation_takes_effect_even_with_a_cached_active_group(): void
    {
        [$group, $site] = $this->holding(true);
        $cachedGroup = $group->fresh('parentOrganization');
        $site->load('organizationGroup');
        $group->update(['status' => 'inactive']);
        $request = Request::create('/');
        $request->attributes->set('holding', $cachedGroup);

        self::assertTrue($cachedGroup->isActive());
        self::assertSame(404, $this->app->make(HoldingController::class)->index($request)->getStatusCode());
        self::assertSame([], $this->app->make(SiteBuilderDataService::class)->buildPublishedPayload($site));
        self::assertNull($this->app->make(HoldingPublicDataService::class)->forGroup($cachedGroup));
    }

    public function test_legacy_public_lookup_never_publishes_live_fields_or_mutates_the_saved_snapshot(): void
    {
        [$group, $site] = $this->holding(true);
        $legacy = ['blocks' => [[
            'type' => 'custom_html', 'content' => ['html' => 'LEGACY-PUBLISHED-CONTENT'],
        ]]];
        $site->update(['published_payload' => $legacy, 'title' => 'PRIVATE-LIVE-TITLE', 'description' => 'PRIVATE-LIVE-DESCRIPTION']);
        $group->parentOrganization->update(['phone' => 'PRIVATE-LIVE-PHONE']);
        $service = $this->app->make(SiteManagementService::class);

        self::assertNotNull($service->getSiteByDomain($site->domain));
        self::assertSame($legacy, $site->fresh()->getPublishedPayload());
        foreach (['subdomain_site', 'landing_site'] as $name) {
            $response = $this->publicResponses($group, $site)[$name];
            self::assertSame(200, $response->getStatusCode());
            $data = $response->getData(true)['data'];
            self::assertSame('Сайт холдинга', $data['site']['title']);
            self::assertSame('Сайт холдинга', $data['current_page']['title']);
            self::assertSame('LEGACY-PUBLISHED-CONTENT', $data['blocks'][0]['content']['html']);
            self::assertStringNotContainsString('PRIVATE-LIVE-', json_encode($data, JSON_THROW_ON_ERROR));
        }
        self::assertSame($legacy, $site->fresh()->getPublishedPayload());

        $creator = User::query()->findOrFail($group->created_by_user_id);
        $service->getOrCreateHoldingLanding($group, $creator);
        $upgraded = $site->fresh()->getPublishedPayload();
        self::assertSame('LEGACY-PUBLISHED-CONTENT', $upgraded['pages'][0]['sections'][0]['content']['html']);
        self::assertSame([], $upgraded['organization']['organization']);
        self::assertArrayNotHasKey('preview_url', $upgraded['site']);
        self::assertStringNotContainsString('PRIVATE-LIVE-', json_encode($upgraded, JSON_THROW_ON_ERROR));
    }

    public function test_valid_preview_can_render_a_draft_but_invalid_token_cannot(): void
    {
        [$group, $site] = $this->holding();
        parse_str((string) parse_url($site->getPreviewUrl(), PHP_URL_QUERY), $query);
        $legacyToken = hash('sha256', $site->id.$site->updated_at->timestamp.config('app.key'));
        foreach (['subdomain_site', 'landing_site'] as $name) {
            $valid = $this->publicResponses($group, $site, $query)[$name];
            self::assertSame(200, $valid->getStatusCode());
            self::assertSame('draft', $valid->getData(true)['data']['runtime']['mode']);
            $invalid = $this->publicResponses($group, $site, ['preview' => 'true', 'token' => 'invalid'])[$name];
            self::assertSame(404, $invalid->getStatusCode());
            $legacy = $this->publicResponses($group, $site, ['preview' => 'true', 'token' => $legacyToken])[$name];
            self::assertSame(404, $legacy->getStatusCode());
        }
    }

    public function test_private_dashboard_keeps_its_access_check_without_requiring_site_publication(): void
    {
        [$group] = $this->holding();
        $user = User::factory()->create();
        $service = Mockery::mock(MultiOrganizationService::class);
        $service->shouldReceive('hasAccessToOrganization')->once()->with($user, $group->parent_organization_id)->andReturnTrue();
        $service->shouldReceive('getOrganizationHierarchy')->once()->with($group->parent_organization_id)->andReturn(['parent' => ['id' => $group->parent_organization_id], 'children' => []]);
        $controller = new HoldingApiController($service, $this->app->make(HoldingPublicDataService::class));
        $request = Request::create('/dashboard');
        $request->setUserResolver(static fn (): User => $user);
        self::assertSame(200, $controller->getDashboardData($group->slug, $request)->getStatusCode());
        self::assertSame(401, $controller->getDashboardData($group->slug, Request::create('/dashboard'))->getStatusCode());

        $denied = Mockery::mock(MultiOrganizationService::class);
        $denied->shouldReceive('hasAccessToOrganization')->once()->with($user, $group->parent_organization_id)->andReturnFalse();
        $controller = new HoldingApiController($denied, $this->app->make(HoldingPublicDataService::class));
        self::assertSame(403, $controller->getDashboardData($group->slug, $request)->getStatusCode());
    }

    private function publicResponses(OrganizationGroup $group, HoldingSite $site, array $query = []): array
    {
        $group = $group->fresh('parentOrganization');
        $root = Request::create('/', 'GET', $query);
        $root->attributes->set('holding', $group);
        $subdomainSite = Request::create('/api/site-data', 'GET', $query);
        $subdomainSite->attributes->set('holding', $group);
        $landingSite = Request::create('/public/site-data', 'GET', ['site_domain' => $site->domain] + $query);

        return [
            'slug' => $this->app->make(HoldingApiController::class)->getPublicData($group->slug),
            'root' => $this->app->make(HoldingController::class)->index($root),
            'subdomain_site' => $this->app->make(HoldingController::class)->getSiteData($subdomainSite),
            'landing_site' => $this->app->make(HoldingLandingController::class)->publicSiteData($landingSite),
        ];
    }

    private function holding(bool $published = false): array
    {
        $parent = Organization::factory()->create(['verification_notes' => 'private-verification']);
        $user = User::factory()->create();
        $group = OrganizationGroup::query()->create([
            'parent_organization_id' => $parent->id, 'created_by_user_id' => $user->id,
            'name' => 'Закрытое имя', 'slug' => 'security-holding-'.uniqid(), 'status' => 'active',
            'settings' => ['private-settings' => true], 'permissions_config' => ['private-permissions' => true],
        ]);
        $site = HoldingSite::query()->create([
            'organization_group_id' => $group->id, 'domain' => $group->slug.'.example.test',
            'title' => 'Сохранённый сайт', 'status' => 'draft', 'is_active' => true,
            'default_locale' => 'ru', 'enabled_locales' => ['ru'], 'created_by_user_id' => $user->id,
        ]);
        $page = HoldingSitePage::query()->create([
            'holding_site_id' => $site->id, 'page_type' => 'home', 'slug' => '/',
            'title' => 'Главная', 'visibility' => 'public', 'is_home' => true, 'is_active' => true,
            'created_by_user_id' => $user->id,
        ]);
        SiteContentBlock::query()->create([
            'holding_site_id' => $site->id, 'holding_site_page_id' => $page->id,
            'block_type' => 'custom_html', 'block_key' => 'privacy-test', 'title' => 'О компании',
            'content' => ['html' => 'Сохранённый контент'], 'is_active' => true, 'status' => 'published',
            'created_by_user_id' => $user->id,
        ]);
        if ($published) {
            $snapshot = $this->app->make(SiteBuilderDataService::class)->buildPublicationSnapshot($site);
            $snapshot['organization'] = [
                'holding' => ['id' => $group->id, 'name' => 'Опубликованное имя', 'slug' => $group->slug, 'description' => 'Сохранённое описание', 'settings' => ['private-settings' => true]],
                'organization' => ['id' => $parent->id, 'name' => 'Публичная компания', 'phone' => '+70000000000', 'verification_notes' => 'private-verification', 'child_organizations' => [['name' => 'Закрытый ребёнок']]],
            ];
            $site->publish($user, $snapshot);
        }

        return [$group, $site];
    }
}
