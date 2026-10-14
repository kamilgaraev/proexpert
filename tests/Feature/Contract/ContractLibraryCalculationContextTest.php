<?php

declare(strict_types=1);

namespace Tests\Feature\Contract;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Contract;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Contract\ContractLibraryService;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class ContractLibraryCalculationContextTest extends TestCase
{
    public function test_calculation_uses_read_only_contract_context_and_preserves_manual_validation(): void
    {
        [$contract, $url, $ids] = $this->contextFixture();
        $before = $contract->fresh()->getRawOriginal();
        $counts = [DB::table('contract_builder_instances')->count(), DB::table('contract_builder_revisions')->count()];
        $body = ['contract_id' => $contract->id, 'values' => [$ids['quantity'] => '100']];

        $result = $this->postJson($url, $body)->assertOk()->json('data');
        self::assertSame('LEGACY-READ-102', $result['values'][$ids['number']]);
        self::assertSame(['amount' => '1200.50', 'currency' => 'RUB'], $result['values'][$ids['amount']]);
        self::assertSame('2026-10-01', $result['values'][$ids['date']]);
        self::assertStringContainsString('LEGACY-READ-102', $result['html']);
        $this->postJson($url, ['contract_id' => $contract->id, 'values' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('values.'.$ids['quantity']);
        $body['values'][$ids['number']] = 'SPOOFED-NUMBER';
        $this->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors('values.'.$ids['number']);
        self::assertSame($before, $contract->fresh()->getRawOriginal());
        self::assertSame($counts, [DB::table('contract_builder_instances')->count(), DB::table('contract_builder_revisions')->count()]);
    }

    public function test_calculation_rejects_foreign_missing_and_revoked_contract_access(): void
    {
        [$contract, $url, $ids] = $this->contextFixture();
        $foreign = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $foreign->id]);
        $foreignContract = Contract::create([
            'organization_id' => $foreign->id, 'project_id' => $project->id,
            'number' => 'FOREIGN-CONTEXT', 'date' => '2026-10-01', 'status' => 'active',
            'total_amount' => 50, 'contract_side_type' => 'general_contract',
        ]);
        foreach ([$foreignContract->id, 2147483647] as $id) {
            $this->postJson($url, ['contract_id' => $id, 'values' => [$ids['quantity'] => '100']])->assertNotFound();
        }
        $contract->organizationViews()->firstOrFail()->update(['access_revoked_at' => now()]);
        $this->postJson($url, ['contract_id' => $contract->id, 'values' => [$ids['quantity'] => '100']])->assertNotFound();
    }

    public function test_calculation_does_not_enroll_a_contract_and_requires_contract_view_permission(): void
    {
        [$contract, $url, $ids] = $this->contextFixture(false);
        $body = ['contract_id' => $contract->id, 'values' => [$ids['quantity'] => '100']];
        $this->postJson($url, $body)->assertNotFound();
        self::assertSame(0, $contract->organizationViews()->count());
        $contract->organizationViews()->create(['organization_id' => $contract->organization_id, 'visibility' => 'active', 'version' => 1]);
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnUsing(static fn (...$arguments): bool => !in_array('contracts.view', $arguments, true));
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->postJson($url, $body)->assertForbidden();
    }

    public function test_optional_context_keeps_template_only_behavior_and_validates_identifier(): void
    {
        [$contract, $url, $ids] = $this->contextFixture();
        foreach ([0, -1, 'invalid'] as $id) {
            $this->postJson($url, ['contract_id' => $id, 'values' => [$ids['quantity'] => '100']])
                ->assertUnprocessable()->assertJsonValidationErrors('contract_id');
        }
        $this->postJson($url, ['values' => [$ids['quantity'] => '100']])->assertUnprocessable();
        $this->postJson($url, ['contract_id' => null, 'values' => [$ids['quantity'] => '100']])->assertUnprocessable();
    }

    private function contextFixture(bool $enrolled = true): array
    {
        $this->withoutMiddleware();
        $owner = Organization::factory()->create();
        $actor = User::factory()->create(['current_organization_id' => $owner->id]);
        $this->actingAs($actor, 'api_admin');
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            $event->request->attributes->set('current_organization_id', $event->request->user()?->current_organization_id);
        });
        $authorization = \Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $this->app->instance(AuthorizationService::class, $authorization);
        $project = Project::factory()->create(['organization_id' => $owner->id]);
        $contract = Contract::create([
            'organization_id' => $owner->id, 'project_id' => $project->id,
            'number' => 'LEGACY-READ-102', 'date' => '2026-10-01', 'status' => 'active',
            'total_amount' => '1200.50', 'currency' => 'RUB', 'contract_side_type' => 'general_contract',
        ]);
        if ($enrolled) {
            $contract->organizationViews()->create(['organization_id' => $owner->id, 'visibility' => 'active', 'version' => 1]);
        }
        $library = new ContractLibraryService($authorization);
        $ids = [];
        $variables = [];
        $nodes = [];
        foreach (['number' => ['text', 'contract.number'], 'amount' => ['money', 'contract.total_amount'], 'date' => ['date', 'contract.date'], 'quantity' => ['number', null]] as $key => [$type, $field]) {
            $definition = ['type' => $type, 'required' => true];
            if ($field !== null) {
                $definition['source'] = ['kind' => 'contract_context', 'field' => $field];
            }
            $created = $library->create($actor, $owner->id, 'variable', $key, $definition, $key);
            $id = $created['item']['id'];
            $library->publish($actor, $owner->id, $id, 1, 1);
            $ids[$key] = $id;
            $variables[$id] = 1;
            $nodes[] = ['type' => 'variable', 'attrs' => ['variableId' => $id]];
            $nodes[] = ['type' => 'text', 'text' => ' '];
        }
        $template = $library->create($actor, $owner->id, 'template', 'Context preview', [
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $nodes]]],
            'variables' => $variables,
        ], 'context-template');
        $library->publish($actor, $owner->id, $template['item']['id'], 1, 1);

        return [$contract, '/api/v1/admin/contract-library/'.$template['item']['id'].'/versions/1/calculate', $ids];
    }
}
