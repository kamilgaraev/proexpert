<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Module;
use App\Models\Project;
use App\Services\Modules\PackageCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class AssistantDomainModuleCandidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_module_domain_limits_its_read_and_observes_fresh_revocation(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $policy = app(AssistantDataAccessPolicy::class);
        [$allowed, $queries] = $this->moduleReads(fn (): bool => $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'projects'));

        self::assertTrue($allowed);
        self::assertNotEmpty($queries);
        self::assertStringContainsString('"modules"."slug" = ?', $queries[0]['query']);
        self::assertSame('project-management', $queries[0]['bindings'][array_key_last($queries[0]['bindings'])]);
        Module::query()->where('slug', 'project-management')->update(['is_active' => false]);
        self::assertFalse($policy->canReadDomain($fixture->owner, $fixture->organization->id, 'projects'));
        self::assertFalse($policy->canReadDomain($fixture->foreignOwner, $fixture->organization->id, 'projects'));
    }

    public function test_explicit_batch_keeps_full_modules_for_distinct_domains_and_public_reads(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $policy = app(AssistantDataAccessPolicy::class);
        [$result, $queries] = $this->moduleReads(fn (): array => $policy->withCurrentChecks($fixture->owner, $fixture->organization->id,
            fn (): array => [
                $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'projects'),
                $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'contracts'),
                $policy->effectiveModuleSlugs($fixture->owner, $fixture->organization->id),
            ], true));

        self::assertTrue($result[0]);
        self::assertTrue($result[1]);
        self::assertContains('project-management', $result[2]);
        self::assertContains('ai-assistant', $result[2]);
        self::assertNotEmpty($queries);
        self::assertStringNotContainsString('"modules"."slug" = ?', $queries[0]['query']);
    }

    public function test_multiple_module_alternatives_keep_full_read_and_allow_the_second_module(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $policy = app(AssistantDataAccessPolicy::class);
        Module::query()->where('slug', 'basic-warehouse')->update(['is_active' => false]);
        [$allowed, $queries] = $this->moduleReads(fn (): bool => $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'operations_assets'));

        self::assertTrue($allowed);
        self::assertNotEmpty($queries);
        self::assertStringNotContainsString('"modules"."slug" = ?', $queries[0]['query']);
    }

    public function test_compiler_keeps_full_modules_after_a_single_domain_check(): void
    {
        $fixture = AssistantRealAuthorizationFixture::create(array_column(app(PackageCatalogService::class)->allPackages(), 'slug'));
        $policy = app(AssistantDataAccessPolicy::class);
        $result = [];
        [, $queries] = $this->moduleReads(function () use ($fixture, $policy, &$result): mixed {
            return (new \ReflectionMethod($policy, 'compileAcl'))->invoke($policy, $fixture->owner, $fixture->organization->id,
                function () use ($fixture, $policy, &$result) {
                    $result = [
                        $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'projects'),
                        $policy->canReadDomain($fixture->owner, $fixture->organization->id, 'contracts'),
                        $policy->effectiveModuleSlugs($fixture->owner, $fixture->organization->id),
                    ];

                    return Project::query()->where('organization_id', $fixture->organization->id);
                });
        });

        self::assertTrue($result[0]);
        self::assertTrue($result[1]);
        self::assertContains('project-management', $result[2]);
        self::assertContains('ai-assistant', $result[2]);
        self::assertNotEmpty($queries);
        self::assertStringNotContainsString('"modules"."slug" = ?', $queries[0]['query']);
    }

    private function moduleReads(callable $read): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $result = $read();
            $queries = array_values(array_filter(DB::getQueryLog(), static fn (array $query): bool => preg_match('/^select\b.*from "modules"/is', $query['query']) === 1));
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$result, $queries];
    }
}
