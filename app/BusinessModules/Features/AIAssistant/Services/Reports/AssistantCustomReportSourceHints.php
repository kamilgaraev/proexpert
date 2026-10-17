<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDomainCatalog;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class AssistantCustomReportSourceHints
{
    public function __construct(private AssistantDomainCatalog $catalog, private AssistantCustomReportSource $sources,
        private AssistantDataAccessPolicy $policy) {}

    public function forRequest(User $actor, int $organizationId, string $query, AssistantRequestUnderstanding $understanding): array
    {
        if (! AssistantCustomReportIntent::matches($query, $understanding)) {
            return [];
        }
        $domains = [];
        foreach (['project' => 'projects', 'contract' => 'contracts', 'estimate' => 'estimates', 'warehouse' => 'warehouse',
            'payment' => 'finance', 'schedule' => 'schedule'] as $entity => $domain) {
            if (in_array($entity, $understanding->requestedEntities, true)) {
                $domains[] = $domain;
            }
        }
        foreach (['people' => '/сотрудник|пользоват|персонал/iu', 'machinery' => '/техник|оборудован/iu',
            'time_tracking' => '/табел|уч[её]т.*врем|отработан/iu', 'procurement' => '/закупк|поставщик/iu',
            'quality' => '/дефект|качеств/iu', 'safety' => '/охран.*труд|безопасност|инцидент/iu',
            'site_requests' => '/заявк.*(?:объект|стройплощад)/iu', 'design' => '/комплект|проектн.*документ|пир/iu'] as $domain => $pattern) {
            if (preg_match($pattern, $query)) {
                $domains[] = $domain;
            }
        }

        return $this->policy->withCurrentChecks($actor, $organizationId, function () use ($actor, $organizationId, $domains): array {
            $hints = [];
            foreach (array_slice(array_values(array_unique($domains)), 0, 3) as $domain) {
                $definition = $this->catalog->definition($domain);
                if ($definition === null) {
                    continue;
                }
                try {
                    $source = $this->sources->resolve($actor, $organizationId, $domain, $definition->entityType, null);
                    $fields = array_slice($source['fields'], 0, 40, true);
                    $hints[] = ['domain' => $domain, 'entity_type' => $definition->entityType,
                        'fields' => array_map(static fn (array $field): string => $field['type'], $fields),
                        'preferred_fields' => array_filter(['counterparty' => isset($fields['counterparty_name']) ? 'counterparty_name' : null,
                            'project' => isset($fields['project_id']) ? 'project_id' : null,
                            'responsible' => isset($fields['assigned_user_id']) ? 'assigned_user_id' : null,
                            'overdue' => isset($fields['is_overdue']) ? 'is_overdue' : null]),
                        'choices' => array_map(static fn (array $field): array => $field['choices'],
                            array_filter($fields, static fn (array $field): bool => isset($field['choices']))),
                        'metrics' => array_map(static fn (array $field): array => $field['aggregations'],
                            array_filter($fields, static fn (array $field): bool => count($field['aggregations']) > 1)),
                        'dimensions' => array_map(static fn (array $field): array => $field['dimensions'],
                            array_filter($fields, static fn (array $field): bool => count($field['aggregations']) > 1 && $field['dimensions'] !== []))];
                } catch (AccessDeniedHttpException|ValidationException) {
                    continue;
                }
            }

            return $hints;
        }, true);
    }
}
