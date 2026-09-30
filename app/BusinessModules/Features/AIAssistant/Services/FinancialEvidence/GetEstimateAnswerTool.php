<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AIPermissionChecker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class GetEstimateAnswerTool extends ReadonlyEstimateTool
{
    public function __construct(private readonly AssistantFinancialAnswerService $answers, private readonly AIPermissionChecker $permissions) {}

    public function getName(): string
    {
        return 'get_estimate_answer';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant.estimate_answer_description');
    }

    public function getParametersSchema(): array
    {
        return $this->schema(['query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 4000],
            'estimate_id' => ['type' => ['integer', 'null'], 'minimum' => 1]]);
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        return $this->executeForSelection($arguments, $user, $organization, null);
    }

    public function executeForSelection(array $arguments, ?User $user, Organization $organization, ?array $selection): array|string
    {
        $execution = app()->bound(AssistantRequestExecutionContext::class) ? app(AssistantRequestExecutionContext::class) : null;
        $execution?->assertCanContinue();
        $actor = $this->actor($user);
        $arguments = $this->validate($arguments, ['query' => ['required', 'string', 'max:4000'], 'estimate_id' => ['nullable', 'integer', 'min:1']]);
        if ((int) $actor->current_organization_id !== (int) $organization->id
            || !$this->permissions->canExecuteTool($actor, $this->getName(), $arguments)) {
            throw new AuthorizationException;
        }
        $estimateId = $arguments['estimate_id'] ?? null;
        $selection = $estimateId !== null && ($selection['estimate_id'] ?? null) === $estimateId ? $selection : null;
        $answer = $this->answers->answer($arguments['query'], (int) $organization->id, $actor, $estimateId, $selection);
        $execution?->assertCanContinue();
        $sourceRefs = $answer['source_refs'] ?? [];
        if ($answer['needs_clarification']) {
            foreach ($answer['resolution']['options'] ?? [] as $option) {
                if (is_array($option) && is_int($option['id'] ?? null)) {
                    $sourceRefs[] = ['entity_type' => 'estimate', 'entity_id' => $option['id'],
                        'organization_id' => (int) $organization->id, 'content_scope' => 'structured',
                        'checked_fields' => ['number', 'name'], 'required_permissions' => ['budget-estimates.view'],
                        'required_domains' => ['estimates'], 'fetched_at' => now()->toISOString()];
                }
            }
        }
        $facts = is_array($answer['financial_evidence'] ?? null)
            ? AssistantEstimateStructuredFacts::positions($answer['financial_evidence'], self::receipt($answer)['positions'], (int) $organization->id)
            : [];

        return ['status' => $answer['resolution']['status'], 'server_formatted_answer' => $answer['text'],
            'financial_evidence' => self::receipt($answer), 'source_refs' => $sourceRefs,
            'validation_status' => $answer['validation_status'], 'needs_clarification' => $answer['needs_clarification'],
            'selection' => $answer['selection'] ?? null, 'resolution' => $answer['resolution'], ...$facts];
    }

    public static function receipt(array $answer): ?array
    {
        $evidence = $answer['financial_evidence'] ?? null;
        if (!is_array($evidence)) {
            return null;
        }
        $receipt = array_intersect_key($evidence, array_flip(['estimate', 'totals', 'stored_totals', 'position_count', 'fetched_at', 'version',
            'validation_status', 'totals_validation_status', 'missing_total_fields', 'aggregation', 'selection']));
        $references = is_array($answer['source_refs'] ?? null) ? $answer['source_refs'] : [];
        $shownIds = [];
        foreach ($references as $reference) {
            if (is_array($reference) && ($reference['entity_type'] ?? null) === 'estimate_item') {
                $shownIds[(string) $reference['entity_id']] = true;
            }
        }
        $receipt['positions'] = array_slice(array_values(array_filter($evidence['positions'] ?? [],
            static fn (array $position): bool => isset($shownIds[(string) $position['id']]))), 0, 50);
        $receipt['source_refs'] = $references;
        return $receipt;
    }
}
