<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class AssistantEstimateResolver
{
    public function __construct(private readonly AssistantDataAccessPolicy $access) {}

    public function resolve(string $query, int $organizationId, User $actor, ?int $pinnedId = null): array
    {
        if (mb_strlen($query) > 4000) {
            throw ValidationException::withMessages(['query' => trans_message('ai_assistant_financial.query_too_long')]);
        }
        if (! $this->access->canReadDomain($actor, $organizationId, 'estimates')) {
            return $this->result('forbidden');
        }
        $selector = $this->selector($query);
        if ($selector === null && $this->isLatestEstimateRequest($query)) {
            return $this->latestEstimate($organizationId, $actor);
        }
        $candidates = [];
        $exact = [];
        foreach (Estimate::query()->where('organization_id', $organizationId)->orderBy('id')->lazyById(100) as $estimate) {
            if (! $this->access->canReadEntity($actor, $organizationId, 'estimate', (int) $estimate->id)) {
                continue;
            }
            $number = mb_strtolower(trim((string) $estimate->number));
            $name = mb_strtolower(trim((string) $estimate->name));
            $needle = mb_strtolower(trim($selector ?? $query));
            if ($needle === $number || $needle === $name) {
                $exact[] = $this->option($estimate);
            } elseif ($selector !== null && $needle !== '' && (str_contains($name, $needle) || str_contains($number, $needle))) {
                $candidates[] = $this->option($estimate);
            } elseif ($selector === null && ($needle === ''
                || ($number !== '' && preg_match('/смет[а-яё]*\s+(?:номер\s+)?'.preg_quote($number, '/').'(?![\pL\pN])/u', $needle))
                || ($number !== '' && preg_match('/^[\pL]{1,12}-\d/u', $number) && preg_match('/(?<![\pL\pN])'.preg_quote($number, '/').'(?![\pL\pN])/u', $needle))
                || ($name !== '' && preg_match('/смет[а-яё]*\s+(?:по\s+)?'.preg_quote($name, '/').'(?![\pL\pN])/u', $needle)))) {
                $candidates[] = $this->option($estimate);
            }
        }
        $options = $exact !== [] ? $exact : $candidates;
        if ($options === [] && $selector === null && $pinnedId !== null && ! preg_match('/(?:друг[а-яё]*\s+смет|переключ|вместо\s+смет|смен[а-яё]*\s+смет)/iu', $query)) {
            $estimate = Estimate::query()->where('organization_id', $organizationId)->find($pinnedId);
            if (! $estimate || ! $this->access->canReadEntity($actor, $organizationId, 'estimate', $pinnedId)) {
                return $this->result('forbidden');
            }

            return $this->result('resolved', [$this->option($estimate)], false);
        }

        return $this->result(count($options) === 1 ? 'resolved' : ($options === [] ? 'not_found' : 'ambiguous'), array_slice($options, 0, 20), $selector !== null || $exact !== []);
    }

    private function latestEstimate(int $organizationId, User $actor): array
    {
        return $this->access->withCurrentChecks($actor, $organizationId, function (AuthorizationService $authorization) use ($organizationId, $actor): array {
            $scope = $this->access->entityQuery($actor, $organizationId, 'estimate');
            if ($scope === null) {
                return $this->result('not_found');
            }

            $organizationFinance = $this->access->canCurrentPermission($actor, $organizationId, 'budget-estimates.finance.view');
            $projectFinance = [];
            $estimates = Estimate::query()->where('estimates.organization_id', $organizationId)
                ->where(static function (Builder $projectScope) use ($organizationId): void {
                    $projectScope->whereNull('estimates.project_id')->orWhereExists(static function ($projects) use ($organizationId): void {
                        $projects->selectRaw('1')->from('projects')->whereColumn('projects.id', 'estimates.project_id')
                            ->where('projects.organization_id', $organizationId);
                    });
                })
                ->whereIn('estimates.id', $scope->select('estimates.id'))
                ->select(['estimates.id', 'estimates.number', 'estimates.name', 'estimates.project_id'])
                ->orderByDesc('estimates.created_at')
                ->orderByDesc('estimates.id')
                ->cursor();

            foreach ($estimates as $estimate) {
                $projectId = $estimate->project_id === null ? null : (int) $estimate->project_id;
                $canReadFinance = $projectId === null
                    ? $organizationFinance
                    : ($projectFinance[$projectId] ??= $authorization->canCurrent($actor, 'budget-estimates.finance.view', [
                        'context_type' => 'project', 'project_id' => $projectId, 'organization_id' => $organizationId,
                    ]));
                if (! $canReadFinance) {
                    continue;
                }

                return $this->result('resolved', [$this->option($estimate)], false);
            }

            return $this->result('not_found');
        }, true);
    }

    private function isLatestEstimateRequest(string $query): bool
    {
        return preg_match('/(?<![\\pL\\pN])последн[а-яё]*\\s+смет[а-яё]*(?![\\pL\\pN])/iu', $query) === 1;
    }

    private function selector(string $query): ?string
    {
        if (preg_match('/смет[а-яё]*\s*(?:№|номер(?:ом)?|номер\s*)\s*[«"“]?([\pL\pN][\pL\pN._\/\-]*)/iu', $query, $match)) {
            return rtrim($match[1], '.');
        }
        if (preg_match('/смет[а-яё]*\s+(?:под\s+названием\s+|с\s+названием\s+)?[«"“]([^»"”]+)[»"”]/iu', $query, $match)) {
            return $match[1];
        }
        if (preg_match('/смет[а-яё]*\s+([\pL\pN]*\d[\pL\pN._\/\-]*)/iu', $query, $match)) {
            return rtrim($match[1], '.');
        }

        return null;
    }

    private function option(Estimate $estimate): array
    {
        return ['id' => (int) $estimate->id, 'number' => (string) $estimate->number, 'name' => (string) $estimate->name,
            'project_id' => (int) $estimate->project_id];
    }

    private function result(string $status, array $options = [], bool $explicit = false): array
    {
        return ['status' => $status, 'estimate_id' => $status === 'resolved' ? $options[0]['id'] : null,
            'options' => $options, 'explicit_selection' => $explicit];
    }
}
