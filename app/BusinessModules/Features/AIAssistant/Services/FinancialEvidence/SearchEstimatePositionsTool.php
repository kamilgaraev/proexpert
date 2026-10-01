<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SearchEstimatePositionsTool extends ReadonlyEstimateTool
{
    public function __construct(
        private readonly AssistantEstimatePositionReadService $reader,
        private readonly AssistantDataAccessPolicy $access,
    ) {}

    public function getName(): string
    {
        return 'search_estimate_positions';
    }

    public function getDescription(): string
    {
        return 'Ищи позиции по словам из названия или точному нормативному коду во всех доступных сметах. Передавай поисковую фразу без вопроса и без названия сметы. Результаты содержат номер и название сметы, номер, название и код позиции, а также ссылки; финансовые суммы не возвращаются. Если search_complete=false, продолжай с тем же query и next_cursor; неполный пустой результат не подтверждает отсутствие совпадений.';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
            'cursor' => ['type' => ['string', 'null'], 'maxLength' => 2048,
                'description' => 'Opaque cursor returned by the previous search page. Reuse the same query.'],
        ], 'required' => ['query'], 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $actor = $this->actor($user);
        $organizationId = (int) $organization->id;

        return $this->access->withCurrentChecks($actor, $organizationId,
            fn (): array => $this->read($arguments, $actor, $organization), true);
    }

    private function read(array $arguments, User $actor, Organization $organization): array
    {
        $organizationId = (int) $organization->id;
        if (! $this->access->canReadDomain($actor, $organizationId, 'estimates')
            || ! $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view')) {
            throw new AuthorizationException;
        }
        if (array_diff(array_keys($arguments), array_keys($this->getParametersSchema()['properties'])) !== []) {
            throw ValidationException::withMessages(['arguments' => [trans_message('ai_assistant_financial.unverified_claim')]]);
        }
        $arguments = Validator::make($arguments, [
            'query' => ['required', 'string', 'max:200'],
            'per_page' => ['sometimes', 'integer', 'between:1,12'],
            'cursor' => ['nullable', 'string', 'max:2048'],
        ])->validate();

        $perPage = (int) ($arguments['per_page'] ?? 12);
        $result = $this->reader->searchAcrossEstimates($arguments['query'], $organizationId, $actor,
            $perPage, $arguments['cursor'] ?? null);
        $matches = array_map(static fn (array $match): array => [
            'estimate' => $match['estimate'],
            'position' => $match['position'],
        ], $result['matches']);
        $facts = AssistantEstimateStructuredFacts::crossEstimatePositions($result['matches'], $organizationId, $result['fetched_at']);
        $evidence = $facts['structured_fact_evidence'] ?? [];

        return [
            'status' => 'success',
            'search_status' => $result['search_status'],
            'search_scope' => 'all_accessible_estimates',
            'search_complete' => $result['search_complete'],
            'has_more' => $result['has_more'],
            'next_cursor' => $result['next_cursor'],
            'meta' => ['returned' => count($matches), 'per_page' => $perPage],
            'matches' => $matches,
            'source_refs' => $evidence['source_refs'] ?? [],
            'server_formatted_facts' => $facts['server_formatted_facts'] ?? '',
            'structured_fact_evidence' => $evidence,
            'fetched_at' => $result['fetched_at'],
            'validation_status' => 'partial',
        ];
    }
}
