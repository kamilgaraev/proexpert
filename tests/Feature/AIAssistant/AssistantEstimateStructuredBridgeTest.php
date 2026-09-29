<?php

declare(strict_types=1);

namespace Tests\Feature\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactVerifier;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimateFinancialSnapshotTool;
use App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence\GetEstimatePositionsTool;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AssistantEstimateStructuredBridgeTest extends TestCase
{
    private User $actor;
    private Organization $organization;
    private Estimate $estimate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $project = Project::factory()->create(['organization_id' => $this->organization->id, 'is_archived' => false]);
        $this->actor = User::factory()->create(['current_organization_id' => $this->organization->id, 'is_active' => true]);
        $this->actor->organizations()->attach($this->organization->id, ['is_active' => true, 'is_owner' => true, 'project_access_mode' => 'all_projects']);
        $authorization = $this->mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturn(true);
        $authorization->shouldReceive('canCurrent')->andReturn(true);
        $authorization->shouldReceive('forCurrentChecks')->andReturnSelf();
        $this->mock(OrganizationEntitlementService::class)->shouldReceive('getEffectiveModules')
            ->andReturn(collect([new Module(['slug' => 'budget-estimates']), new Module(['slug' => 'project-management'])]));
        $this->mock(UserProjectAccessService::class)->shouldReceive('queryAccessibleProjects')
            ->andReturnUsing(fn (User $actor, int $organizationId) => Project::query()->where('organization_id', $organizationId));
        $this->app->forgetInstance(AssistantDataAccessPolicy::class);
        $this->estimate = Estimate::query()->create(['organization_id' => $this->organization->id, 'project_id' => $project->id,
            'number' => 'QA-BRIDGE-1', 'name' => 'Проверка шапки', 'status' => 'draft', 'estimate_date' => '2026-09-29',
            'total_amount' => '123.45']);
        EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => '1',
            'name' => 'Позиция проверки', 'item_type' => 'work', 'quantity' => '2.12345678',
            'total_amount' => '123.45', 'unit_price' => '58.14', 'is_manual' => true]);
    }

    public function test_financial_snapshot_exposes_verified_identity_fields_without_changing_money_only_fallback(): void
    {
        $tool = app(GetEstimateFinancialSnapshotTool::class)->execute(['estimate_id' => $this->estimate->id], $this->actor, $this->organization);
        self::assertIsArray($tool);
        $row = $tool['structured_fact_evidence']['rows'][0];
        self::assertSame(['number', 'name', 'status', 'estimate_date'], array_keys($row['fields']));
        self::assertSame($this->estimate->id, $row['source_ref']['entity_id']);
        self::assertSame('structured', $row['source_ref']['content_scope']);
        self::assertSame($tool['financial_evidence']['fetched_at'], $row['source_ref']['fetched_at']);
        self::assertSame($tool['financial_evidence']['version'], $row['source_ref']['version']);
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadReference($this->actor, $this->organization->id, $row['source_ref']));

        $verifier = new AssistantStructuredFactVerifier;
        $identity = $verifier->guard('Покажи номер, название, дату и текущий статус сметы', 'Непроверенный текст', [$tool]);
        self::assertFalse($identity['needs_clarification']);
        self::assertSame([$row['source_ref']], $identity['source_refs']);
        $identityText = str_replace('\\-', '-', $identity['text']);
        self::assertStringContainsString('QA-BRIDGE-1', $identityText);
        self::assertStringContainsString('Проверка шапки', $identityText);
        self::assertStringContainsString('2026-09-29', $identityText);
        self::assertStringContainsString('Черновик', $identityText);

        $money = $verifier->guard('Какая точная сумма сметы?', 'Непроверенный текст', [$tool]);
        self::assertFalse($money['needs_clarification']);
        self::assertSame($tool['financial_evidence']['source_refs'], $money['source_refs']);
        self::assertStringContainsString('123.45', $money['text']);
        $mixed = $verifier->guard('Каков статус и сумма сметы?', 'Непроверенный текст', [$tool]);
        self::assertTrue($mixed['needs_clarification']);
        self::assertSame([], $mixed['source_refs']);
    }

    public function test_position_read_has_exact_page_evidence_and_snapshot_alone_cannot_claim_positions_or_owner(): void
    {
        $query = 'Покажи позиции с точным количеством и суммой каждой позиции';
        self::assertFalse(AssistantFactIntentClassifier::isMoneyOnly($query));
        self::assertContains(['position_number'], AssistantFactIntentClassifier::requirements($query));
        $snapshot = app(GetEstimateFinancialSnapshotTool::class)->execute(['estimate_id' => $this->estimate->id], $this->actor, $this->organization);
        self::assertIsArray($snapshot);
        $verifier = new AssistantStructuredFactVerifier;
        foreach ([$query, 'Кто ответственный за смету?'] as $unsupported) {
            $guarded = $verifier->guard($unsupported, 'Непроверенный текст', [$snapshot]);
            self::assertTrue($guarded['needs_clarification']);
            self::assertSame([], $guarded['source_refs']);
        }

        $positions = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $this->estimate->id, 'page' => 1, 'per_page' => 10], $this->actor, $this->organization);
        self::assertIsArray($positions);
        self::assertCount(2, $positions['structured_fact_evidence']['rows']);
        $positionRow = $positions['structured_fact_evidence']['rows'][1];
        self::assertSame('2.12345678', $positionRow['fields']['quantity']);
        self::assertSame('123.45', $positionRow['fields']['total_amount']);
        self::assertSame(['position_number', 'name', 'quantity', 'total_amount'], $positionRow['source_ref']['checked_fields']);
        self::assertTrue(app(AssistantDataAccessPolicy::class)->canReadReference($this->actor, $this->organization->id, $positionRow['source_ref']));
        $guarded = $verifier->guard($query, 'Непроверенный текст', [$positions]);
        self::assertFalse($guarded['needs_clarification']);
        self::assertCount(2, $guarded['source_refs']);
        self::assertStringContainsString('2.12345678', $guarded['text']);
        self::assertStringContainsString('123.45', $guarded['text']);
    }

    public function test_structured_position_receipts_are_bounded_by_formatter_limit(): void
    {
        for ($index = 2; $index <= 30; $index++) {
            EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => (string) $index,
                'name' => 'Позиция '.$index, 'item_type' => 'work', 'quantity' => '1',
                'total_amount' => '1.00', 'unit_price' => '1.00', 'is_manual' => true]);
        }
        $positions = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $this->estimate->id, 'page' => 1, 'per_page' => 30], $this->actor, $this->organization);
        self::assertIsArray($positions);
        self::assertCount(30, $positions['positions']);
        self::assertCount(AssistantStructuredFactFormatter::MAX_ROWS, $positions['structured_fact_evidence']['rows']);
        self::assertTrue($positions['structured_fact_evidence']['truncated']);
        self::assertSame($this->estimate->id, $positions['structured_fact_evidence']['rows'][0]['entity_id']);
        $verifier = new AssistantStructuredFactVerifier;
        $guarded = $verifier->guard('Покажи позиции сметы', 'Непроверенный текст', [$positions]);
        self::assertTrue($guarded['structured_evidence_truncated']);
        self::assertStringContainsString('Показаны 24 из 30 найденных позиций', $guarded['text']);
        self::assertCount(AssistantStructuredFactFormatter::MAX_ROWS, $guarded['source_refs']);

        $next = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $this->estimate->id, 'page' => 2, 'per_page' => 24], $this->actor, $this->organization);
        self::assertIsArray($next);
        self::assertCount(6, $next['positions']);
        $nextGuarded = $verifier->guard('Покажи позиции сметы', 'Непроверенный текст', [$next]);
        self::assertFalse($nextGuarded['structured_evidence_truncated']);
        self::assertStringContainsString('Позиция 30', $nextGuarded['text']);
        self::assertStringNotContainsString('Показаны 24 из 30', $nextGuarded['text']);
    }

    public function test_merged_receipts_report_actual_visible_position_count_after_row_cap(): void
    {
        for ($index = 2; $index <= 30; $index++) {
            EstimateItem::query()->create(['estimate_id' => $this->estimate->id, 'position_number' => (string) $index,
                'name' => 'Позиция '.$index, 'item_type' => 'work', 'quantity' => '1',
                'total_amount' => '1.00', 'unit_price' => '1.00', 'is_manual' => true]);
        }
        $frozenAt = now();
        Carbon::setTestNow($frozenAt);
        try {
            $first = app(GetEstimateFinancialSnapshotTool::class)->execute(['estimate_id' => $this->estimate->id], $this->actor, $this->organization);
            $positions = app(GetEstimatePositionsTool::class)->execute(['estimate_id' => $this->estimate->id, 'page' => 1, 'per_page' => 30], $this->actor, $this->organization);
            Carbon::setTestNow($frozenAt->copy()->addSecond());
            $second = app(GetEstimateFinancialSnapshotTool::class)->execute(['estimate_id' => $this->estimate->id], $this->actor, $this->organization);
        } finally {
            Carbon::setTestNow();
        }
        self::assertIsArray($first);
        self::assertIsArray($second);
        self::assertIsArray($positions);
        $guarded = (new AssistantStructuredFactVerifier)->guard('Покажи позиции сметы', 'Непроверенный текст', [$first, $second, $positions]);
        $visiblePositions = array_values(array_filter($guarded['source_refs'], static fn (array $ref): bool => $ref['entity_type'] === 'estimate_item'));
        self::assertCount(23, $visiblePositions);
        self::assertTrue($guarded['structured_evidence_truncated']);
        self::assertStringContainsString('Показаны 23 из 30 найденных позиций', $guarded['text']);
    }
}
