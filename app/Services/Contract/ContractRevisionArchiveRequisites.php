<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use App\Models\Project;

final class ContractRevisionArchiveRequisites
{
    public static function capture(Contract $contract, array $revision, int $organizationId): array
    {
        $projectId = $contract->project_id;
        $projectAvailable = $projectId !== null && Project::query()->whereKey($projectId)->accessibleByOrganization($organizationId)->exists();
        $counterparty = null;
        foreach ($revision['parties'] as $party) {
            if ((int) ($party['linked_organization_id'] ?? 0) === $organizationId) {
                continue;
            }
            $name = trim((string) (($party['legal_name'] ?? '') ?: ($party['name'] ?? '')));
            if ($name !== '') {
                $counterparty = $name;
                break;
            }
        }
        $terms = (new ContractRevisionTermsCompiler)->compile($revision)['terms'];

        return [
            'primary_project_id' => $projectAvailable ? (int) $projectId : null,
            'document_number' => self::contextValue($revision, 'contract.number', $contract->number),
            'document_date' => self::contextValue($revision, 'contract.date', $contract->date?->toDateString()),
            'counterparty_name' => $counterparty,
            'description' => $terms['subject'] ?? $contract->subject,
        ];
    }

    private static function contextValue(array $revision, string $field, ?string $fallback): ?string
    {
        foreach ($revision['definitions'] as $definition) {
            $source = $definition['definition']['source'] ?? [];
            if (($source['kind'] ?? null) === 'contract_context' && ($source['field'] ?? null) === $field
                && array_key_exists($definition['id'], $revision['values'])) {
                $value = $revision['values'][$definition['id']];

                return is_string($value) && trim($value) !== '' ? $value : null;
            }
        }

        return $fallback;
    }
}
