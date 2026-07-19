<?php

declare(strict_types=1);

namespace App\Services\LegalArchive\Profiles;

use App\BusinessModules\Features\LegalArchive\Models\LegalArchiveDocumentTypeProfile;
use Closure;
use InvalidArgumentException;

use function trans_message;

final class LegalDocumentProfileRegistry
{
    /** @var Closure(int, string): ?array<string, mixed> */
    private Closure $organizationProfileLoader;

    /** @var array<string, array<string, mixed>> */
    private array $standardProfiles;

    /**
     * @param  null|callable(int, string): ?array<string, mixed>  $organizationProfileLoader
     * @param  null|array<string, array<string, mixed>>  $standardProfiles
     */
    public function __construct(?callable $organizationProfileLoader = null, ?array $standardProfiles = null)
    {
        $this->organizationProfileLoader = $organizationProfileLoader !== null
            ? Closure::fromCallable($organizationProfileLoader)
            : static function (int $organizationId, string $code): ?array {
                $profile = LegalArchiveDocumentTypeProfile::query()
                    ->forOrganization($organizationId)
                    ->active()
                    ->where('code', $code)
                    ->first();

                return $profile?->toArray();
            };

        $configuredProfiles = $standardProfiles ?? config('legal-document-profiles', []);
        $this->standardProfiles = is_array($configuredProfiles) ? $configuredProfiles : [];
    }

    public function find(int $organizationId, string $code): LegalDocumentProfile
    {
        if ($organizationId <= 0 || trim($code) === '') {
            throw $this->notFound();
        }

        if (isset($this->standardProfiles[$code])) {
            return $this->fromStandardProfile($code, $this->standardProfiles[$code]);
        }

        $custom = ($this->organizationProfileLoader)($organizationId, $code);

        if (
            $custom === null
            || (int) ($custom['organization_id'] ?? 0) !== $organizationId
            || (string) ($custom['code'] ?? '') !== $code
            || ($custom['is_active'] ?? false) !== true
        ) {
            throw $this->notFound();
        }

        $baseCode = (string) ($custom['base_code'] ?? '');
        $base = $this->standardProfiles[$baseCode] ?? null;

        if (! is_array($base)) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.base_not_found'));
        }

        $baseProfile = $this->fromStandardProfile($baseCode, $base);
        $customSchema = $this->arrayValue($custom, 'schema');

        if (array_intersect_key($customSchema, $baseProfile->schema) !== []) {
            throw new InvalidArgumentException(trans_message('legal_archive.profiles.base_field_override_forbidden'));
        }

        return new LegalDocumentProfile(
            code: $code,
            baseCode: $baseCode,
            label: (string) ($custom['name'] ?? $baseProfile->label),
            category: $baseProfile->category,
            schema: [...$baseProfile->schema, ...$customSchema],
            requiredFileRoles: $this->uniqueStrings([
                ...$baseProfile->requiredFileRoles,
                ...$this->stringList($custom, 'required_file_roles'),
            ]),
            requiredFields: $this->uniqueStrings([
                ...$baseProfile->requiredFields,
                ...$this->stringList($custom, 'required_fields'),
            ]),
            requiresSignature: array_key_exists('requires_signature', $custom) && $custom['requires_signature'] !== null
                ? (bool) $custom['requires_signature']
                : $baseProfile->requiresSignature,
            workflowTemplateId: $this->nullableString($custom['workflow_template_id'] ?? null),
            retentionPolicy: $this->nullableString($custom['retention_policy'] ?? null)
                ?? $baseProfile->retentionPolicy,
            confidentialityLevel: $this->nullableString($custom['confidentiality_level'] ?? null)
                ?? $baseProfile->confidentialityLevel,
            isActive: true,
            lockVersion: max(0, (int) ($custom['lock_version'] ?? 0)),
        );
    }

    /** @param array<string, mixed> $definition */
    private function fromStandardProfile(string $code, array $definition): LegalDocumentProfile
    {
        return new LegalDocumentProfile(
            code: $code,
            baseCode: $code,
            label: (string) ($definition['label'] ?? $code),
            category: (string) ($definition['category'] ?? 'other'),
            schema: $this->arrayValue($definition, 'schema'),
            requiredFileRoles: $this->stringList($definition, 'required_file_roles'),
            requiredFields: $this->stringList($definition, 'required_fields'),
            requiresSignature: (bool) ($definition['requires_signature'] ?? false),
            workflowTemplateId: $this->nullableString($definition['workflow_template_id'] ?? null),
            retentionPolicy: $this->nullableString($definition['retention_policy'] ?? null),
            confidentialityLevel: $this->nullableString($definition['confidentiality_level'] ?? null) ?? 'internal',
            isActive: true,
            lockVersion: 0,
        );
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function arrayValue(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function stringList(array $values, string $key): array
    {
        $value = $values[$key] ?? [];

        return is_array($value) ? $this->uniqueStrings($value) : [];
    }

    /** @param array<mixed> $values @return list<string> */
    private function uniqueStrings(array $values): array
    {
        $strings = array_filter($values, static fn (mixed $value): bool => is_string($value) && $value !== '');

        return array_values(array_unique($strings));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function notFound(): InvalidArgumentException
    {
        return new InvalidArgumentException(trans_message('legal_archive.profiles.not_found'));
    }
}
