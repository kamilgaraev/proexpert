<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Reporting;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

final readonly class FinanceSourceAccessPolicy
{
    public function visibleRefs(
        ReportExecutionContext $context,
        mixed $sourceRefs,
        array $allowedTypes,
    ): array {
        if (!is_array($sourceRefs) || !array_is_list($sourceRefs)) {
            return [];
        }
        $allowed = array_fill_keys($allowedTypes, true);
        $resources = [];
        foreach ($context->scope->resources as $resource) {
            $resources[$resource->kind.':'.$resource->id] = true;
        }
        $visible = [];
        foreach ($sourceRefs as $ref) {
            if (!is_array($ref)
                || !is_string($ref['type'] ?? null)
                || !isset($allowed[$ref['type']])
                || (!is_int($ref['id'] ?? null) && !ctype_digit((string) ($ref['id'] ?? '')))) {
                continue;
            }
            $id = (string) $ref['id'];
            if (!isset($resources[$ref['type'].':'.$id])) {
                continue;
            }
            $visible[] = [...$ref, 'id' => $id];
        }

        return $visible;
    }
}
