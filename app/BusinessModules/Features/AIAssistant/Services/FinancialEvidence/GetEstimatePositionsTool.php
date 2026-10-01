<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantStructuredFactFormatter;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class GetEstimatePositionsTool extends ReadonlyEstimateTool
{
    public function __construct(
        private readonly AssistantEstimatePositionReadService $reader,
        private readonly AssistantDataAccessPolicy $access,
    ) {}

    public function getName(): string
    {
        return 'get_estimate_positions';
    }

    public function getDescription(): string
    {
        return trans_message('ai_assistant_financial.positions_description')
            .' Можно найти позиции по точному номеру или полному названию сметы, словам из названия позиции или шифру расценки. Если известен точный номер позиции, передай его в position_number отдельно от шифра или поисковых слов в query. Поиск сметы по фрагменту не поддерживается. Если название неоднозначно или превышен лимит вариантов, попроси точный номер сметы. Для одной найденной позиции можно прочитать её ресурсы с отдельной пагинацией. Проверяй has_more и читай next_page, если нужен полный состав. Соседние позиции не являются доказанными ресурсами этой позиции. Общий итог сметы не подтверждается страницей позиций.';
    }

    public function getParametersSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'estimate_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'estimate_selector' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 255,
                'description' => 'Точный номер или полное название сметы из запроса. При неоднозначности названия уточни точный номер. Используй либо это поле, либо estimate_id.'],
            'query' => ['type' => ['string', 'null'], 'maxLength' => 200,
                'description' => 'Слова из названия позиции, номер позиции или шифр расценки. Передавай отдельный поисковый текст, без инструкции и номера сметы.'],
            'position_number' => ['type' => ['string', 'null'], 'minLength' => 1, 'maxLength' => 50,
                'description' => 'Точный номер одной позиции сметы. Применяется дополнительным фильтром вместе с query.'],
            'position_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
            'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'include_composition' => ['type' => 'boolean',
                'description' => 'Верни ресурсы только когда найдена одна позиция; состав ограничен страницей.'],
            'composition_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000],
            'composition_per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
        ], 'required' => [], 'additionalProperties' => false];
    }

    public function execute(array $arguments, ?User $user, Organization $organization): array|string
    {
        $actor = $this->actor($user);

        return $this->access->withCurrentChecks($actor, (int) $organization->id,
            fn (): array => $this->read($arguments, $actor, $organization), true);
    }

    private function read(array $arguments, User $actor, Organization $organization): array
    {
        if (! $this->access->canReadDomain($actor, (int) $organization->id, 'estimates')
            || ! $this->access->canCurrentPermission($actor, (int) $organization->id, 'budget-estimates.finance.view')) {
            throw new AuthorizationException;
        }
        if (array_diff(array_keys($arguments), array_keys($this->getParametersSchema()['properties'])) !== []) {
            throw ValidationException::withMessages(['arguments' => [trans_message('ai_assistant_financial.unverified_claim')]]);
        }
        $arguments = Validator::make($arguments, [
            'estimate_id' => ['nullable', 'integer', 'min:1'],
            'estimate_selector' => ['nullable', 'string', 'min:1', 'max:255'],
            'query' => ['nullable', 'string', 'max:200'],
            'position_number' => ['nullable', 'string', 'min:1', 'max:50'],
            'position_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'between:1,1000000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'include_composition' => ['sometimes', 'boolean'],
            'composition_page' => ['sometimes', 'integer', 'between:1,1000000'],
            'composition_per_page' => ['sometimes', 'integer', 'between:1,20'],
        ])->validate();

        $estimateId = isset($arguments['estimate_id']) ? (int) $arguments['estimate_id'] : null;
        $selector = isset($arguments['estimate_selector']) ? trim($arguments['estimate_selector']) : null;
        if (($estimateId === null) === ($selector === null || $selector === '')) {
            throw ValidationException::withMessages(['estimate_id' => [trans_message('ai_assistant_financial.unverified_claim')]]);
        }
        if ($estimateId === null) {
            $resolution = $this->reader->resolveSelector($selector, (int) $organization->id, $actor);
            if ($resolution['status'] !== 'resolved') {
                return ['status' => $resolution['status'], 'estimate' => null, 'positions' => [],
                    'message' => $resolution['message'],
                    'meta' => ['page' => 1, 'per_page' => count($resolution['options']), 'total' => $resolution['total'],
                        'has_more' => $resolution['has_more'], 'next_page' => null],
                    'source_refs' => [], 'validation_status' => 'partial', 'needs_clarification' => true,
                    'resolution' => $resolution];
            }
            $estimateId = (int) $resolution['estimate_id'];
        }

        $page = (int) ($arguments['page'] ?? 1);
        $perPage = (int) ($arguments['per_page'] ?? 20);
        $result = $this->reader->page($estimateId, (int) $organization->id, $actor, $page, $perPage,
            $arguments['query'] ?? null, isset($arguments['position_id']) ? (int) $arguments['position_id'] : null,
            (bool) ($arguments['include_composition'] ?? false), (int) ($arguments['composition_page'] ?? 1),
            (int) ($arguments['composition_per_page'] ?? 20),
            isset($arguments['position_number']) ? trim($arguments['position_number']) : null);
        $evidence = $result['evidence'];
        $positions = $evidence['positions'];
        $facts = AssistantEstimateStructuredFacts::positions($evidence, $positions, (int) $organization->id);
        $facts['structured_fact_evidence']['validation_scope'] = $evidence['validation_scope'];
        $sourceRefs = AssistantEstimateEvidenceService::publicSourceReferences($evidence, $positions);
        $composition = $result['composition'];

        if (is_array($composition)) {
            $sourceRefs = array_merge($sourceRefs, $composition['source_refs'] ?? []);
            $resourceFactRows = $composition['fact_rows'] ?? [];
            if (($composition['status'] ?? null) === 'returned') {
                $baseEvidence = $facts['structured_fact_evidence'];
                $baseEvidence['rows'] = array_merge($baseEvidence['rows'], $resourceFactRows);
                $baseEvidence['source_refs'] = array_column($baseEvidence['rows'], 'source_ref');
                $baseEvidence['version'] = hash('sha256', json_encode($baseEvidence['rows'], JSON_THROW_ON_ERROR));
                $baseEvidence['validation_scope'] = 'returned_positions_and_resources';
                $baseEvidence['composition_page'] = array_intersect_key($composition, array_flip(['scope', 'position_id', 'total', 'page', 'per_page', 'has_more', 'next_page']));
                $facts['structured_fact_evidence'] = $baseEvidence;
                $resourceFacts = AssistantStructuredFactFormatter::payload($resourceFactRows, $evidence['fetched_at']);
                $facts['server_formatted_facts'] = trim($facts['server_formatted_facts']."\n".($resourceFacts['server_formatted_facts'] ?? ''));
            }
            unset($composition['source_refs'], $composition['fact_rows']);
        }

        return ['estimate' => $evidence['estimate'], 'positions' => $positions, 'meta' => $result['meta'],
            ...($composition !== null ? ['composition' => $composition] : []),
            'source_refs' => $sourceRefs,
            'server_formatted_facts' => $facts['server_formatted_facts'],
            'structured_fact_evidence' => $facts['structured_fact_evidence'],
            'fetched_at' => $evidence['fetched_at'], 'validation_status' => $evidence['validation_status'],
            'validation_scope' => $evidence['validation_scope'], 'needs_clarification' => false];
    }
}
