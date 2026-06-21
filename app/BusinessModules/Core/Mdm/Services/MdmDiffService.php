<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Mdm\Services;

use function trans_message;

class MdmDiffService
{
    public function __construct(
        private readonly MdmEntityGovernanceRegistry $governanceRegistry
    ) {}

    public function build(string $entityType, array $currentValues, array $proposedValues): array
    {
        $diff = [];
        $blockers = [];
        $warnings = [];
        $normalizedProposed = [];

        foreach ($proposedValues as $field => $after) {
            if (! is_string($field)) {
                continue;
            }

            $policy = $this->governanceRegistry->classifyField($entityType, $field);
            $before = $currentValues[$field] ?? null;
            $label = $this->governanceRegistry->fieldLabel($entityType, $field);

            if ($policy === 'locked') {
                $blockers[] = $this->blocker('locked_field', $field, $label, trans_message('mdm.blockers.locked_field'));

                continue;
            }

            if ($policy === 'unsupported') {
                $blockers[] = $this->blocker('unsupported_field', $field, $label, trans_message('mdm.blockers.unsupported_field'));

                continue;
            }

            $normalizedProposed[$field] = $after;

            if ($this->valuesEqual($before, $after)) {
                continue;
            }

            $diff[] = [
                'field' => $field,
                'label' => $label,
                'before' => $before,
                'after' => $after,
                'policy' => $policy,
                'critical' => $this->governanceRegistry->isCritical($entityType, $field),
                'requires_one_c_review' => $this->governanceRegistry->isOneCField($entityType, $field),
            ];
        }

        if ($diff === [] && $blockers === []) {
            $warnings[] = [
                'code' => 'no_changes',
                'message' => trans_message('mdm.warnings.no_changes'),
            ];
        }

        return [
            'proposed_values' => $normalizedProposed,
            'diff' => $diff,
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }

    public function hasCriticalChanges(array $diff): bool
    {
        foreach ($diff as $item) {
            if (($item['critical'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function valuesEqual(mixed $before, mixed $after): bool
    {
        if (is_bool($before) || is_bool($after)) {
            return (bool) $before === (bool) $after;
        }

        if (is_numeric($before) && is_numeric($after)) {
            return (string) $before === (string) $after;
        }

        return $before === $after;
    }

    private function blocker(string $code, string $field, string $label, string $message): array
    {
        return [
            'code' => $code,
            'field' => $field,
            'label' => $label,
            'message' => $message,
        ];
    }
}
