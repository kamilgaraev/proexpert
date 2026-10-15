<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Features\Budgeting\Services\BudgetCatalogService;
use App\Enums\AuthSessionStatus;
use App\Models\User;
use App\Models\UserAuthSession;
use App\Services\Auth\WebAuthTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\Support\AssistantRealAuthorizationFixture;
use Tests\TestCase;

final class BudgetCatalogTenantIsolationSecurityTest extends TestCase
{
    public static function catalogs(): array
    {
        return [
            'period' => ['periods', ['period_type' => 'month', 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-31', 'status' => 'open']],
            'scenario' => ['scenarios', ['scenario_type' => 'custom', 'is_default' => true, 'is_active' => true]],
            'center' => ['responsibility-centers', ['center_type' => 'department', 'is_active' => true]],
            'article' => ['articles', ['budget_kind' => 'bdr', 'flow_direction' => 'expense', 'management_cost_class' => 'non_labor']],
        ];
    }

    #[DataProvider('catalogs')]
    public function test_http_catalog_writes_keep_the_authorized_organization(string $kind, array $fields): void
    {
        [$actor, $fixture] = $this->actor();
        $service = app(BudgetCatalogService::class);
        $payload = ['code' => 'TENANT-'.$kind, 'name' => 'Original catalog'] + $fields;
        $foreign = $this->store($service, $fixture->foreignOwner, $kind, ['code' => 'FOREIGN', 'name' => 'Foreign catalog'] + $fields);
        $foreignBefore = $foreign->fresh()->getRawOriginal();
        $base = '/api/v1/admin/budgeting/'.$kind;
        $created = $this->postJson($base, $payload + ['organization_id' => $fixture->foreignOrganization->id], $actor->authHeaders());
        $created->assertCreated()->assertJsonPath('data.organization_id', $actor->organization->id);
        $uuid = $created->json('data.id');
        self::assertIsString($uuid);

        foreach ([null, $actor->organization->id] as $organizationId) {
            $input = array_replace($payload, ['name' => 'Allowed update '.($organizationId ?? 'omitted')]);
            if ($organizationId !== null) {
                $input['organization_id'] = $organizationId;
            }
            $this->putJson($base.'/'.$uuid, $input, $actor->authHeaders())
                ->assertOk()->assertJsonPath('data.organization_id', $actor->organization->id)
                ->assertJsonPath('data.name', $input['name']);
        }

        $this->putJson($base.'/'.$uuid, array_replace($payload, [
            'organization_id' => $fixture->foreignOrganization->id, 'name' => 'Authorized catalog update',
        ]), $actor->authHeaders())->assertOk()->assertJsonPath('data.organization_id', $actor->organization->id);
        $persisted = $foreign->newQuery()->where('uuid', $uuid)->firstOrFail();
        self::assertSame($actor->organization->id, $persisted->organization_id);
        self::assertSame('Authorized catalog update', $persisted->name);
        self::assertFalse($foreign->newQuery()->where('organization_id', $fixture->foreignOrganization->id)->where('uuid', $uuid)->exists());

        $this->putJson($base.'/'.$foreign->uuid, $payload, $actor->authHeaders())->assertUnprocessable();
        self::assertSame($foreignBefore, $foreign->fresh()->getRawOriginal());
    }

    #[DataProvider('catalogs')]
    public function test_direct_catalog_update_preserves_tenant_and_record_identity(string $kind, array $fields): void
    {
        [$actor, $fixture] = $this->actor();
        $service = app(BudgetCatalogService::class);
        $payload = ['code' => 'DIRECT-'.$kind, 'name' => 'Original catalog'] + $fields;
        $model = $this->store($service, $actor->user, $kind, $payload);
        $before = $model->refresh()->getRawOriginal();
        $input = array_replace($payload, [
            'name' => 'Allowed direct update', 'organization_id' => $fixture->foreignOrganization->id,
            'uuid' => (string) Str::uuid(), 'created_by' => $fixture->foreignOwner->id, 'updated_by' => $fixture->foreignOwner->id,
        ]);
        $updated = match ($kind) {
            'periods' => $service->updatePeriod($actor->user, $model->uuid, $input),
            'scenarios' => $service->updateScenario($actor->user, $model->uuid, $input),
            'responsibility-centers' => $service->updateResponsibilityCenter($actor->user, $model->uuid, $input),
            'articles' => $service->updateArticle($actor->user, $model->uuid, $input),
        };
        foreach (['id', 'uuid', 'organization_id', 'created_by', 'updated_by'] as $field) {
            self::assertSame($before[$field] ?? null, $updated->getRawOriginal($field));
        }
        self::assertSame('Allowed direct update', $updated->name);
    }

    private function store(BudgetCatalogService $service, User $user, string $kind, array $input): Model
    {
        return match ($kind) {
            'periods' => $service->storePeriod($user, $input),
            'scenarios' => $service->storeScenario($user, $input),
            'responsibility-centers' => $service->storeResponsibilityCenter($user, $input),
            'articles' => $service->storeArticle($user, $input),
        };
    }

    private function actor(): array
    {
        $fixture = AssistantRealAuthorizationFixture::create(['working-entry', 'finance-contracts']);
        $user = $fixture->addMember(['budgeting' => [
            'budgeting.periods.manage', 'budgeting.scenarios.manage', 'budgeting.cfo.manage', 'budgeting.articles.manage',
        ]], ['admin.access']);
        $sessionUuid = (string) Str::uuid();
        UserAuthSession::query()->create([
            'user_id' => $user->id, 'organization_id' => $fixture->organization->id,
            'session_uuid' => $sessionUuid, 'device_fingerprint' => hash('sha256', $sessionUuid),
            'device_name' => 'Catalog isolation test', 'ip_address' => '127.0.0.1',
            'risk_score' => 0, 'risk_flags' => [], 'status' => AuthSessionStatus::Active,
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
        $token = app(WebAuthTokenService::class)->issue($user, 'admin', $sessionUuid, $fixture->organization->id, false);

        return [new AdminApiTestContext($fixture->organization, $user, $token->accessToken), $fixture];
    }
}
