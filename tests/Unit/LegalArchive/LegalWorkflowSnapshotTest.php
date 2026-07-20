<?php

declare(strict_types=1);

namespace Tests\Unit\LegalArchive;

use App\BusinessModules\Core\ImmutableAudit\Services\ImmutableAuditIntegrityService;
use App\Services\LegalArchive\Workflow\DTO\WorkflowOverride;
use App\Services\LegalArchive\Workflow\LegalWorkflowTemplateService;
use DomainException;
use PHPUnit\Framework\TestCase;

final class LegalWorkflowSnapshotTest extends TestCase
{
    public function test_snapshot_is_canonical_and_independent_from_later_template_changes(): void
    {
        $service = new LegalWorkflowTemplateService(new ImmutableAuditIntegrityService);
        $steps = [
            $this->legalStep(),
            $this->financeStep(sequence: 20),
        ];

        $first = $service->snapshotFromDefinitions(15, 'contract', 3, $steps, WorkflowOverride::none('submit-1'), 71, str_repeat('a', 64));
        $steps[1]['label'] = 'Изменённый финансовый контроль';
        $second = $service->snapshotFromDefinitions(15, 'contract', 4, $steps, WorkflowOverride::none('submit-2'));

        self::assertSame('Финансовый контроль', $first->payload['steps'][1]['label']);
        self::assertSame([
            'organization_id' => 15,
            'template_id' => 71,
            'code' => 'contract',
            'version' => 3,
            'definition_hash' => str_repeat('a', 64),
        ], $first->payload['template_identity']);
        self::assertSame(['step_overrides' => [], 'additional_steps' => []], $first->payload['override']);
        self::assertNotSame($first->hash, $second->hash);
        self::assertSame($first->hash, hash('sha256', $service->canonicalJson($first->payload)));
    }

    public function test_mandatory_legal_step_cannot_be_removed_disabled_or_reordered(): void
    {
        $service = new LegalWorkflowTemplateService(new ImmutableAuditIntegrityService);

        foreach ([
            ['enabled' => false],
            ['sequence' => 99],
            ['actor_type' => 'user', 'actor_reference' => '8'],
        ] as $override) {
            try {
                $service->snapshotFromDefinitions(
                    15,
                    'contract',
                    3,
                    [$this->legalStep(), $this->financeStep(sequence: 20)],
                    new WorkflowOverride('submit-1', stepOverrides: ['legal_review' => $override]),
                );
                self::fail('Обязательный юридический шаг удалось изменить');
            } catch (DomainException $exception) {
                self::assertSame('legal_workflow_mandatory_step_override_forbidden', $exception->getMessage());
            }
        }
    }

    public function test_parallel_steps_are_sorted_deterministically_and_optional_override_is_validated(): void
    {
        $service = new LegalWorkflowTemplateService(new ImmutableAuditIntegrityService);
        $snapshot = $service->snapshotFromDefinitions(
            15,
            'contract',
            3,
            [$this->financeStep('finance_b', 20), $this->legalStep(), $this->financeStep('finance_a', 20)],
            new WorkflowOverride('submit-1', stepOverrides: [
                'finance_a' => [
                    'actor_type' => 'user',
                    'actor_reference' => '44',
                    'due_at' => '2099-08-01T09:00:00+03:00',
                ],
            ]),
        );

        self::assertSame(['legal_review', 'finance_a', 'finance_b'], array_column($snapshot->payload['steps'], 'key'));
        self::assertSame('44', $snapshot->payload['steps'][1]['actor_reference']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('legal_workflow_actor_reference_required');
        $service->snapshotFromDefinitions(
            15,
            'contract',
            3,
            [$this->legalStep(), $this->financeStep(sequence: 20)],
            new WorkflowOverride('submit-2', stepOverrides: ['finance' => ['actor_type' => 'user']]),
        );
    }

    /** @return array<string, mixed> */
    private function legalStep(): array
    {
        return [
            'key' => 'legal_review',
            'label' => 'Юридическая проверка',
            'sequence' => 10,
            'parallel_group' => 'legal',
            'required' => true,
            'policy_key' => 'legal_review',
            'actor_type' => 'role',
            'actor_reference' => 'legal_reviewer',
            'due_in_hours' => 24,
        ];
    }

    /** @return array<string, mixed> */
    private function financeStep(string $key = 'finance', int $sequence = 20): array
    {
        return [
            'key' => $key,
            'label' => 'Финансовый контроль',
            'sequence' => $sequence,
            'parallel_group' => 'finance',
            'required' => false,
            'policy_key' => null,
            'actor_type' => 'role',
            'actor_reference' => 'finance_reviewer',
            'due_in_hours' => 48,
        ];
    }
}
