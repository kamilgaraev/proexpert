<?php

declare(strict_types=1);

namespace Tests\Unit\Crm;

use App\BusinessModules\Features\Crm\Models\CrmActivity;
use App\BusinessModules\Features\Crm\Models\CrmCompany;
use App\BusinessModules\Features\Crm\Models\CrmContact;
use App\BusinessModules\Features\Crm\Models\CrmDeal;
use App\BusinessModules\Features\Crm\Models\CrmLead;
use App\BusinessModules\Features\Crm\Models\CrmSource;
use App\BusinessModules\Features\Crm\Services\CrmRegistryService;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

final class CrmRegistryServiceScopedValidationTest extends TestCase
{
    private CrmRegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CrmRegistryService::class);
    }

    /**
     * @dataProvider ownerScopedEntitiesProvider
     */
    public function test_create_rejects_owner_from_another_organization(string $entity): void
    {
        $organization = Organization::factory()->create();
        [, $otherUser] = $this->createOrganizationWithUser();

        $this->expectValidationError('owner_user_id', function () use ($entity, $organization, $otherUser): void {
            $this->createEntityThroughService($entity, $organization->id, [
                'owner_user_id' => $otherUser->id,
            ]);
        });
    }

    /**
     * @dataProvider ownerScopedEntitiesProvider
     */
    public function test_create_rejects_inactive_owner_in_current_organization(string $entity): void
    {
        [$organization, $inactiveUser] = $this->createOrganizationWithUser(false);

        $this->expectValidationError('owner_user_id', function () use ($entity, $organization, $inactiveUser): void {
            $this->createEntityThroughService($entity, $organization->id, [
                'owner_user_id' => $inactiveUser->id,
            ]);
        });
    }

    /**
     * @dataProvider sourceScopedEntitiesProvider
     */
    public function test_create_rejects_source_from_another_organization(string $entity): void
    {
        $organization = Organization::factory()->create();
        [$otherOrganization] = $this->createOrganizationWithUser();
        $otherSource = $this->createSource($otherOrganization->id);

        $this->expectValidationError('source_id', function () use ($entity, $organization, $otherSource): void {
            $this->createEntityThroughService($entity, $organization->id, [
                'source_id' => $otherSource->id,
            ]);
        });
    }

    /**
     * @dataProvider sourceScopedEntitiesProvider
     */
    public function test_create_accepts_current_organization_and_global_sources(string $entity): void
    {
        $organization = Organization::factory()->create();
        $organizationSource = $this->createSource($organization->id);
        $globalSource = $this->createSource(null);

        $withOrganizationSource = $this->createEntityThroughService($entity, $organization->id, [
            'source_id' => $organizationSource->id,
        ]);
        $withGlobalSource = $this->createEntityThroughService($entity, $organization->id, [
            'source_id' => $globalSource->id,
        ]);

        $this->assertSame($organizationSource->id, $withOrganizationSource->source_id);
        $this->assertSame($globalSource->id, $withGlobalSource->source_id);
    }

    /**
     * @dataProvider sourceScopedEntitiesProvider
     */
    public function test_update_allows_clearing_owner_and_source_references(string $entity): void
    {
        [$organization, $owner] = $this->createOrganizationWithUser();
        $source = $this->createSource($organization->id);
        $record = $this->createEntityDirectly($entity, $organization->id, [
            'owner_user_id' => $owner->id,
            'source_id' => $source->id,
        ]);

        $updated = $this->updateEntityThroughService($entity, $organization->id, (string) $record->getKey(), [
            'owner_user_id' => '',
            'source_id' => null,
        ]);

        $this->assertNull($updated->owner_user_id);
        $this->assertNull($updated->source_id);
    }

    public function test_update_allows_clearing_activity_owner_and_links(): void
    {
        [$organization, $owner] = $this->createOrganizationWithUser();
        $company = $this->createCompany($organization->id);
        $contact = $this->createContact($organization->id, ['company_id' => $company->id]);
        $lead = $this->createLead($organization->id, [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
        ]);
        $deal = $this->createDeal($organization->id, ['company_id' => $company->id]);
        $activity = $this->createActivity($organization->id, [
            'owner_user_id' => $owner->id,
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'lead_id' => $lead->id,
            'deal_id' => $deal->id,
        ]);

        $updated = $this->service->updateActivity($organization->id, (string) $activity->id, [
            'owner_user_id' => '',
            'company_id' => null,
            'contact_id' => '',
            'lead_id' => null,
            'deal_id' => '',
        ], null);

        $this->assertNull($updated->owner_user_id);
        $this->assertNull($updated->company_id);
        $this->assertNull($updated->contact_id);
        $this->assertNull($updated->lead_id);
        $this->assertNull($updated->deal_id);
    }

    public function test_deal_query_does_not_eager_load_files_for_uuid_deals(): void
    {
        $method = new ReflectionMethod(CrmRegistryService::class, 'dealQuery');
        $method->setAccessible(true);

        $query = $method->invoke($this->service, 1, true);
        $eagerLoads = $query->getEagerLoads();

        $this->assertArrayHasKey('company', $eagerLoads);
        $this->assertArrayHasKey('activities', $eagerLoads);
        $this->assertArrayNotHasKey('files', $eagerLoads);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ownerScopedEntitiesProvider(): array
    {
        return [
            'company' => ['company'],
            'contact' => ['contact'],
            'lead' => ['lead'],
            'activity' => ['activity'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function sourceScopedEntitiesProvider(): array
    {
        return [
            'company' => ['company'],
            'contact' => ['contact'],
            'lead' => ['lead'],
        ];
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createOrganizationWithUser(bool $active = true): array
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->create();

        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => $active,
        ]);

        return [$organization, $user];
    }

    private function expectValidationError(string $field, Closure $action): void
    {
        try {
            $action();
            $this->fail("Expected validation error for {$field}.");
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function createEntityThroughService(string $entity, int $organizationId, array $overrides = []): Model
    {
        return match ($entity) {
            'company' => $this->service->createCompany($organizationId, array_merge([
                'name' => $this->uniqueLabel('Компания'),
            ], $overrides), null),
            'contact' => $this->service->createContact($organizationId, array_merge([
                'full_name' => $this->uniqueLabel('Контакт'),
            ], $overrides), null),
            'lead' => $this->service->createLead($organizationId, array_merge([
                'title' => $this->uniqueLabel('Лид'),
            ], $overrides), null),
            'activity' => $this->service->createActivity($organizationId, array_merge([
                'type' => 'call',
                'subject' => $this->uniqueLabel('Активность'),
            ], $overrides), null),
            default => throw new \InvalidArgumentException("Unknown CRM entity {$entity}."),
        };
    }

    private function updateEntityThroughService(string $entity, int $organizationId, string $id, array $data): Model
    {
        return match ($entity) {
            'company' => $this->service->updateCompany($organizationId, $id, $data, null),
            'contact' => $this->service->updateContact($organizationId, $id, $data, null),
            'lead' => $this->service->updateLead($organizationId, $id, $data, null),
            default => throw new \InvalidArgumentException("Unknown CRM entity {$entity}."),
        };
    }

    private function createEntityDirectly(string $entity, int $organizationId, array $overrides = []): Model
    {
        return match ($entity) {
            'company' => $this->createCompany($organizationId, $overrides),
            'contact' => $this->createContact($organizationId, $overrides),
            'lead' => $this->createLead($organizationId, $overrides),
            default => throw new \InvalidArgumentException("Unknown CRM entity {$entity}."),
        };
    }

    private function createSource(?int $organizationId): CrmSource
    {
        return CrmSource::query()->create([
            'organization_id' => $organizationId,
            'code' => 'source-' . Str::lower((string) Str::uuid()),
            'label' => $this->uniqueLabel('Источник'),
            'channel_type' => 'manual',
            'is_active' => true,
            'settings' => [],
        ]);
    }

    private function createCompany(int $organizationId, array $overrides = []): CrmCompany
    {
        return CrmCompany::query()->create(array_merge([
            'organization_id' => $organizationId,
            'name' => $this->uniqueLabel('Компания'),
            'company_type' => 'legal_entity',
            'roles' => [],
            'status' => 'new',
            'tags' => [],
            'custom_fields' => [],
        ], $overrides));
    }

    private function createContact(int $organizationId, array $overrides = []): CrmContact
    {
        return CrmContact::query()->create(array_merge([
            'organization_id' => $organizationId,
            'full_name' => $this->uniqueLabel('Контакт'),
            'messengers' => [],
            'is_primary' => false,
            'status' => 'active',
        ], $overrides));
    }

    private function createLead(int $organizationId, array $overrides = []): CrmLead
    {
        return CrmLead::query()->create(array_merge([
            'organization_id' => $organizationId,
            'title' => $this->uniqueLabel('Лид'),
            'status' => 'new',
            'priority' => 'normal',
            'utm' => [],
            'raw_source_data' => [],
        ], $overrides));
    }

    private function createDeal(int $organizationId, array $overrides = []): CrmDeal
    {
        return CrmDeal::query()->create(array_merge([
            'organization_id' => $organizationId,
            'title' => $this->uniqueLabel('Сделка'),
            'pipeline_code' => 'default',
            'stage_code' => 'new',
            'status' => 'open',
            'currency' => 'RUB',
            'custom_fields' => [],
        ], $overrides));
    }

    private function createActivity(int $organizationId, array $overrides = []): CrmActivity
    {
        return CrmActivity::query()->create(array_merge([
            'organization_id' => $organizationId,
            'type' => 'call',
            'status' => 'planned',
            'subject' => $this->uniqueLabel('Активность'),
        ], $overrides));
    }

    private function uniqueLabel(string $prefix): string
    {
        return $prefix . ' ' . Str::random(8);
    }
}
