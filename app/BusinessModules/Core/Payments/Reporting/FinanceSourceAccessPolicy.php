<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportScope;

final readonly class FinanceSourceAccessPolicy
{
    public function allowsAggregate(ReportScope $scope, mixed $sourceRefs, array $allowedTypes): bool
    {
        $restricted = [];
        $restrictedTypes = [];
        foreach ($scope->resources as $resource) {
            if (in_array($resource->kind, $allowedTypes, true)) {
                $restricted[$resource->kind.':'.$resource->id] = true;
                $restrictedTypes[$resource->kind] = true;
            }
        }
        if ($restrictedTypes === []) {
            return true;
        }
        if (! is_array($sourceRefs)) {
            return false;
        }
        $relevant = false;
        foreach ($sourceRefs as $ref) {
            if (! is_array($ref) || ! is_string($ref['type'] ?? null)
                || ! isset($restrictedTypes[$ref['type']])) {
                continue;
            }
            $relevant = true;
            if ((! is_int($ref['id'] ?? null) && ! ctype_digit((string) ($ref['id'] ?? '')))
                || ! isset($restricted[$ref['type'].':'.(string) $ref['id']])) {
                return false;
            }
        }

        return $relevant;
    }

    public function visibleRefs(
        ReportExecutionContext $context,
        mixed $sourceRefs,
        array $allowedTypes,
    ): array {
        if (! is_array($sourceRefs) || ! array_is_list($sourceRefs)) {
            return [];
        }
        $allowed = array_fill_keys($allowedTypes, true);
        $resources = [];
        foreach ($context->scope->resources as $resource) {
            $resources[$resource->kind.':'.$resource->id] = true;
        }
        $visible = [];
        foreach ($sourceRefs as $ref) {
            if (! is_array($ref)
                || ! is_string($ref['type'] ?? null)
                || ! isset($allowed[$ref['type']])
                || (! is_int($ref['id'] ?? null) && ! ctype_digit((string) ($ref['id'] ?? '')))) {
                continue;
            }
            $id = (string) $ref['id'];
            if (! isset($resources[$ref['type'].':'.$id])) {
                continue;
            }
            $visible[] = [...$ref, 'id' => $id];
        }

        return $visible;
    }
}
