<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Generation;

use InvalidArgumentException;

final readonly class ReportPermissionTranslationGenerator
{
    public function __construct(
        private array $reports,
        private array $permissions,
    ) {}

    public static function fromProject(string $root): self
    {
        $reports = require $root.'/lang/ru/reports.php';
        $permissions = require $root.'/lang/ru/permissions.php';

        if (! is_array($reports) || ! is_array($permissions)) {
            throw new InvalidArgumentException('report_translation_source_invalid');
        }

        return new self($reports, $permissions);
    }

    /** @param list<string> $codes @param list<string> $groups @param list<string> $permissionSlugs */
    public function generate(array $codes, array $groups, array $permissionSlugs): array
    {
        $titles = [];
        foreach ($codes as $code) {
            $titles[$code] = $this->russian($this->reports['catalog'][$code] ?? null, 'report_title_translation_invalid');
        }

        $translatedGroups = [];
        foreach ($groups as $group) {
            $translatedGroups[$group] = $this->russian($this->reports['catalog_groups'][$group] ?? null, 'report_group_translation_invalid');
        }

        sort($permissionSlugs, SORT_STRING);
        $translatedPermissions = [];
        foreach (array_values(array_unique($permissionSlugs)) as $permission) {
            $translatedPermissions[$permission] = $this->permission($permission);
        }

        return [
            'contract_version' => '1.0.0',
            'groups' => $translatedGroups,
            'titles' => $titles,
            'permissions' => $translatedPermissions,
        ];
    }

    private function permission(string $permission): string
    {
        $value = $this->permissions['values'][$permission] ?? null;
        if (is_string($value) && $this->isRussian($value)) {
            return $value;
        }

        $subjects = $this->permissions['subjects'] ?? null;
        $actions = $this->permissions['actions'] ?? null;
        if (! is_array($subjects) || ! is_array($actions)) {
            throw new InvalidArgumentException('report_permission_translation_invalid');
        }

        $keys = array_keys($subjects);
        usort($keys, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        foreach ($keys as $subject) {
            $subjectLabel = $subjects[$subject] ?? null;
            if (! is_string($subjectLabel)
                || ! $this->isRussian($subjectLabel)
                || ($permission !== $subject && ! str_starts_with($permission, $subject.'.'))) {
                continue;
            }

            $action = $permission === $subject ? '' : substr($permission, strlen($subject) + 1);
            if ($action === '') {
                return $subjectLabel;
            }

            $actionLabel = $actions[$action] ?? $actions[strrchr($action, '.') ?: $action] ?? null;
            if (is_string($actionLabel) && $this->isRussian($actionLabel)) {
                return $subjectLabel.': '.$actionLabel;
            }
        }

        throw new InvalidArgumentException('report_permission_translation_invalid');
    }

    private function russian(mixed $value, string $error): string
    {
        if (! is_string($value) || ! $this->isRussian($value)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private function isRussian(string $value): bool
    {
        return trim($value) !== '' && preg_match('/\\p{Cyrillic}/u', $value) === 1;
    }
}
